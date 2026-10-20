<?php

namespace App\Chatbot;

use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EntityResolver
{
    public function __construct(private Access $access) {}

    public function normalize(string $message): string
    {
        $message = Str::lower(trim($message));
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        $message = str_replace(['?', '!', ',', ';', '"', "'"], ' ', $message);
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return trim($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(string $message, User $user): array
    {
        $params = [];
        $normalized = $this->normalize($message);

        if (preg_match('/\b(pr|po|wo|grn|inv|dn|cn|exp|idn)-?\d{4}-\d{2,}\b/i', $message, $m)) {
            $params['document_number'] = strtoupper($m[0]);
        }

        if (preg_match('/\b(v-\d+|mat-\d+|prj-[\w-]+)\b/i', $message, $m)) {
            $params['code'] = strtoupper($m[1]);
        }

        if (preg_match('/\b(this month|current month|today|this week|last month)\b/i', $normalized, $m)) {
            $params['period'] = $m[1];
        }

        if (preg_match('/\b(draft|submitted|approved|rejected|pending|posted|paid|unpaid|outstanding|overdue)\b/i', $normalized, $m)) {
            $params['status_hint'] = strtolower($m[1]);
        }

        // Skip entity guessing for how-to / help questions — short words like
        // "to", "PR", "PO" falsely match vendors/projects and crash or confuse answers.
        if (! $this->looksLikeHelpQuestion($normalized)) {
            $vendor = $this->resolveVendor($message, $user);
            if ($vendor !== null) {
                $params['vendor'] = $vendor;
                if (($vendor['ambiguous'] ?? false) === true) {
                    $params['vendor_ambiguous'] = $vendor['matches'];
                } elseif (isset($vendor['id'])) {
                    $params['vendor_id'] = $vendor['id'];
                }
            }

            $project = $this->resolveProject($message, $user);
            if ($project !== null) {
                $params['project'] = $project;
                if (($project['ambiguous'] ?? false) === true) {
                    $params['project_ambiguous'] = $project['matches'];
                } elseif (isset($project['id'])) {
                    $params['project_id'] = $project['id'];
                }
            }
        }

        return $params;
    }

    private function looksLikeHelpQuestion(string $normalized): bool
    {
        return (bool) preg_match(
            '/\b(how (do|can|to|should)|where (can|do|to)|what (is|are) the steps|help me|guide me|steps to|tell me how)\b/',
            $normalized
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveVendor(string $message, User $user): ?array
    {
        if (! $this->access->allows($user, 'vendors.view') && ! $user->is_admin) {
            return null;
        }

        $normalized = $this->normalize($message);
        $vendors = Vendor::query()->orderBy('name')->get(['id', 'vendor_code', 'name', 'status']);

        $exactCode = $vendors->first(function (Vendor $v) use ($normalized) {
            $code = strtolower((string) $v->vendor_code);

            return $code !== '' && strlen($code) >= 3 && preg_match('/\b'.preg_quote($code, '/').'\b/', $normalized);
        });
        if ($exactCode) {
            return $this->vendorPayload($exactCode);
        }

        $matches = $vendors->filter(function (Vendor $vendor) use ($normalized) {
            $name = strtolower($vendor->name);
            if (strlen($name) < 3) {
                return false;
            }

            return str_contains($normalized, $name)
                || $this->fuzzyContains($normalized, $name);
        })->values();

        if ($matches->isEmpty()) {
            // Avoid matching the English "to" in "how to …"
            if (preg_match('/\b(?:for|with|owe|vendor)\s+([a-z0-9][a-z0-9 &\.\-]{2,60})/i', $message, $m)
                || preg_match('/\b(?:owe|pay)\s+to\s+([a-z0-9][a-z0-9 &\.\-]{2,60})/i', $message, $m)) {
                $needle = $this->normalize($m[1]);
                if (strlen($needle) >= 3) {
                    $matches = $vendors->filter(function (Vendor $v) use ($needle) {
                        $name = strtolower($v->name);
                        if (str_contains($name, $needle) || str_contains($needle, $name)) {
                            return true;
                        }
                        similar_text($name, $needle, $percent);

                        return $percent >= 78;
                    })->values();
                }
            }
        }

        return $this->pickMatch($matches, fn (Vendor $v) => $this->vendorPayload($v), 'vendor');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveProject(string $message, User $user): ?array
    {
        if (! $this->access->allows($user, 'projects.view') && ! $user->is_admin) {
            return null;
        }

        $normalized = $this->normalize($message);
        $projects = Project::query()->visibleTo($user)->orderBy('project_name')->get(['id', 'project_code', 'project_name']);

        $exactCode = $projects->first(function (Project $p) use ($normalized) {
            $code = strtolower((string) $p->project_code);

            return $code !== '' && strlen($code) >= 2 && preg_match('/\b'.preg_quote($code, '/').'\b/', $normalized);
        });
        if ($exactCode) {
            return $this->projectPayload($exactCode);
        }

        $matches = $projects->filter(function (Project $project) use ($normalized) {
            $name = strtolower($project->project_name);
            if (strlen($name) < 3) {
                return false;
            }

            return str_contains($normalized, $name) || $this->fuzzyContains($normalized, $name);
        })->values();

        if ($matches->isEmpty() && preg_match('/\b(?:project|find project|open project)\s+([a-z0-9][a-z0-9 &\.\-]{2,60})/i', $message, $m)) {
            $needle = $this->normalize($m[1]);
            if (strlen($needle) >= 3) {
                $matches = $projects->filter(function (Project $p) use ($needle) {
                    $name = strtolower($p->project_name);
                    if (str_contains($name, $needle) || str_contains($needle, $name)) {
                        return true;
                    }
                    similar_text($name, $needle, $percent);

                    return $percent >= 78;
                })->values();
            }
        }

        return $this->pickMatch($matches, fn (Project $p) => $this->projectPayload($p), 'project');
    }

    public function findPurchaseOrder(string $number, User $user): ?PurchaseOrder
    {
        if (! $this->access->allows($user, 'purchase_orders.view') && ! $user->is_admin) {
            return null;
        }

        $order = PurchaseOrder::query()->with('project')->where('po_number', $number)->first();
        if (! $order || ! $this->access->canAccessProject($user, $order->project)) {
            return null;
        }

        return $order;
    }

    public function findPurchaseRequest(string $number, User $user): ?PurchaseRequest
    {
        if (! $this->access->allows($user, 'purchase_requests.view') && ! $user->is_admin) {
            return null;
        }

        $pr = PurchaseRequest::query()->with('project')->where('pr_number', $number)->first();
        if (! $pr || ! $this->access->canAccessProject($user, $pr->project)) {
            return null;
        }

        return $pr;
    }

    public function findWorkOrder(string $number, User $user): ?WorkOrder
    {
        if (! $this->access->allows($user, 'work_orders.view') && ! $user->is_admin) {
            return null;
        }

        $wo = WorkOrder::query()->with('project')->where('work_order_number', $number)->first();
        if (! $wo || ! $this->access->canAccessProject($user, $wo->project)) {
            return null;
        }

        return $wo;
    }

    public function findInvoice(string $number, User $user): ?PurchaseInvoice
    {
        if (! $this->access->allows($user, 'invoices.view') && ! $user->is_admin) {
            return null;
        }

        $invoice = PurchaseInvoice::query()->with('project')->where('invoice_number', $number)->first();
        if (! $invoice || ! $this->access->canAccessProject($user, $invoice->project)) {
            return null;
        }

        return $invoice;
    }

    public function findMaterial(string $codeOrName, User $user): ?Material
    {
        if (! $this->access->allows($user, 'inventory.view') && ! $user->is_admin) {
            return null;
        }

        $needle = trim($codeOrName);

        return Material::query()
            ->where('material_code', $needle)
            ->orWhere('name', 'like', '%'.$needle.'%')
            ->orderBy('name')
            ->first();
    }

    /**
     * @template T
     * @param  Collection<int, T>  $matches
     * @param  callable(T): array<string, mixed>  $mapper
     * @return array<string, mixed>|null
     */
    private function pickMatch(Collection $matches, callable $mapper, string $type): ?array
    {
        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() === 1) {
            return $mapper($matches->first());
        }

        return [
            'ambiguous' => true,
            'type' => $type,
            'matches' => $matches->take(5)->map($mapper)->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vendorPayload(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'vendor_code' => $vendor->vendor_code,
            'name' => $vendor->name,
            'status' => $vendor->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_code' => $project->project_code,
            'project_name' => $project->project_name,
        ];
    }

    private function fuzzyContains(string $haystack, string $needle): bool
    {
        if (strlen($needle) < 4) {
            return false;
        }

        $words = preg_split('/\s+/', $needle) ?: [];
        $hits = 0;
        foreach ($words as $word) {
            if (strlen($word) >= 3 && str_contains($haystack, $word)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($words) * 0.6));
    }
}
