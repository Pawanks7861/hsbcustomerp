<?php

namespace App\Chatbot;

class KnowledgeBase
{
    /**
     * @return list<array{id: string, questions: list<string>, keywords: list<string>, answer: string, module: string, route: ?string, permission: ?string}>
     */
    public function entries(): array
    {
        return [
            [
                'id' => 'help.create_project',
                'questions' => [
                    'how do i create a new project', 'how to create a project', 'how to add a project',
                    'create project', 'new project', 'add project',
                ],
                'keywords' => ['project', 'new project', 'add project'],
                'topics' => ['project'],
                'answer' => "To create a project:\n1. Open **Projects** from the sidebar.\n2. Click **+ New Project**.\n3. Enter Project Name and Project Code.\n4. Save.\nYou need the `projects.create` permission.",
                'module' => 'projects',
                'route' => '/projects/create',
                'permission' => 'projects.create',
            ],
            [
                'id' => 'help.add_vendor',
                'questions' => [
                    'how can i add a vendor', 'how to create vendor', 'how to add a vendor',
                    'add vendor', 'create vendor', 'new vendor',
                ],
                'keywords' => ['vendor', 'create vendor', 'add vendor'],
                'topics' => ['vendor', 'supplier'],
                'answer' => "To add a vendor:\n1. Open **Vendors**.\n2. Click **+ Add Vendor**.\n3. Enter Name and other details (GST, bank, party type).\n4. Save.\nYou can also use **Import** on the Vendors list with an Excel/CSV template.",
                'module' => 'vendors',
                'route' => '/vendors/create',
                'permission' => 'vendors.create',
            ],
            [
                'id' => 'help.create_boq',
                'questions' => [
                    'how do i create a boq', 'how to create boq', 'how to create a boq',
                    'create bill of quantities', 'add boq', 'create boq',
                ],
                'keywords' => ['boq', 'bill of quantities'],
                'topics' => ['boq', 'bill of quantities'],
                'answer' => "To create a BOQ:\n1. Open a **Project**.\n2. Go to **BOQ**.\n3. Click **+ New BOQ** and enter the title.\n4. Add items manually or use **Import Excel** on the BOQ page (draft revision only).\n5. Submit/approve according to your BOQ permissions.",
                'module' => 'boq',
                'route' => '/projects',
                'permission' => 'boq.create',
            ],
            [
                'id' => 'help.import_excel',
                'questions' => [
                    'how do i import data from excel', 'how to import excel', 'how to import data',
                    'import csv', 'import excel', 'excel import',
                ],
                'keywords' => ['import', 'excel', 'csv'],
                'topics' => ['import', 'excel', 'csv'],
                'answer' => "To import data:\n1. Open the module list (Vendors, Materials, Projects, etc.).\n2. Click **Import**.\n3. Download the Excel/CSV template.\n4. Fill rows, upload, preview errors, then confirm.\nOr open **Imports** in the sidebar for history and all templates.\nWorkflow documents (GRN, invoices, stock postings) are not bulk-posted from Excel.",
                'module' => 'imports',
                'route' => '/imports/history',
                'permission' => 'projects.view',
            ],
            [
                'id' => 'help.create_pr',
                'questions' => [
                    'how do i create a purchase requisition', 'how to create a purchase requisition',
                    'how to create pr', 'how to create a pr', 'how do i create pr',
                    'create pr', 'create purchase request', 'create purchase requisition',
                    'purchase request', 'new pr', 'make a pr', 'make pr',
                ],
                'keywords' => ['purchase', 'requisition', 'pr', 'purchase request'],
                'topics' => ['pr', 'purchase request', 'purchase requisition', 'requisition'],
                'answer' => "To create a purchase requisition (PR):\n1. Open a **Project** → **Purchase Requests**.\n2. Click **New request**.\n3. Add line items (optionally linked to BOQ items).\n4. Save as draft, then **Submit** for approval.\nRates are not entered on PR screens in this ERP.",
                'module' => 'purchase_requests',
                'route' => '/projects',
                'permission' => 'purchase_requests.create',
            ],
            [
                'id' => 'help.create_po',
                'questions' => [
                    'how do i create a purchase order', 'how to create a purchase order',
                    'how to create po', 'how to create a po', 'how do i create po',
                    'create po', 'create purchase order', 'new po', 'make a po',
                ],
                'keywords' => ['purchase order', 'po'],
                'topics' => ['po', 'purchase order'],
                'answer' => "To create a purchase order (PO):\n1. Ensure a **Purchase Request** is **approved**.\n2. Open Project → **Purchase Orders** (or create from the approved PR flow).\n3. Select vendor, enter commercial terms/rates.\n4. Save as draft, then submit for approval.\nApproved PO rates can only change via the **rate amendment** workflow.",
                'module' => 'purchase_orders',
                'route' => '/projects',
                'permission' => 'purchase_orders.create',
            ],
            [
                'id' => 'help.grn',
                'questions' => [
                    'how do i record a goods receipt', 'how to create grn', 'how to record grn',
                    'create grn', 'receive goods', 'how to do grn', 'goods receipt',
                    'how to receive material', 'how to receive stock',
                ],
                'keywords' => ['grn', 'goods receipt', 'receive goods'],
                'topics' => ['grn', 'goods receipt', 'receive'],
                'answer' => "To record a GRN (add received stock):\n1. Open Project → **Goods Receipt Notes** / Store GRN.\n2. Create a GRN from an approved PO.\n3. Enter accepted quantities, then submit/approve/post per your store permissions.\nPosting updates inventory stock — do not post twice.\n\nTo add a new material *master* item (name/code), use Project → **Inventory** → **Materials** instead.",
                'module' => 'grn',
                'route' => '/projects',
                'permission' => 'grn.create',
            ],
            [
                'id' => 'help.inventory_materials',
                'questions' => [
                    'how to add items in inventory', 'how to add inventory items', 'how to add material',
                    'how to add materials', 'how to create material', 'add items in inventory',
                    'add inventory item', 'create material', 'new material', 'material master',
                    'how to add stock item', 'how do i add items in inventory',
                ],
                'keywords' => ['inventory', 'material', 'materials', 'stock item', 'item master'],
                'topics' => ['inventory', 'material', 'materials', 'stock item'],
                'answer' => "To add inventory / material master items:\n1. Open a **Project**.\n2. Go to **Inventory** → **Materials** (Material Master).\n3. Enter Name, Unit, optional Category / Minimum stock.\n4. Save — a material code is generated.\n5. Or use **Import** on that page to bulk-upload materials.\n\nThis creates the item catalog. To increase **stock quantity**, receive goods via a **GRN** from an approved PO (or use stock adjustment if you have permission).",
                'module' => 'inventory',
                'route' => '/projects',
                'permission' => 'inventory.create',
            ],
            [
                'id' => 'help.inventory_stock',
                'questions' => [
                    'how to check stock', 'how to see stock', 'where is stock balance',
                    'how to view inventory stock', 'current stock',
                ],
                'keywords' => ['stock', 'stock balance', 'inventory stock'],
                'topics' => ['stock', 'stock balance'],
                'answer' => "To view stock:\n1. Open a **Project** → **Inventory** → **Stock** (or Inventory Dashboard).\n2. Or open **Reports** → stock balance report if you have report access.\nStock increases when a GRN is **posted**, and decreases when issues/transfers are posted.",
                'module' => 'inventory',
                'route' => '/projects',
                'permission' => 'inventory.view',
            ],
            [
                'id' => 'help.invoice',
                'questions' => [
                    'how do i create a purchase invoice', 'how to create invoice', 'how to create a purchase invoice',
                    'create invoice', 'create purchase invoice', 'new invoice',
                ],
                'keywords' => ['invoice', 'purchase invoice'],
                'topics' => ['invoice', 'purchase invoice'],
                'answer' => "To create a purchase invoice:\n1. Open Project → **Purchase Invoices** → **+ Invoice**.\n2. Link the vendor/PO and bill line quantities (partial invoices supported).\n3. Apply tax/round-off as permitted, then submit/approve.\nDraft invoices do not affect vendor outstanding until recorded/approved per workflow.",
                'module' => 'invoices',
                'route' => '/projects',
                'permission' => 'invoices.create',
            ],
            [
                'id' => 'help.payment',
                'questions' => [
                    'how can i record a vendor payment', 'how to record payment', 'how to pay vendor',
                    'record payment', 'pay vendor', 'vendor payment', 'make payment',
                ],
                'keywords' => ['payment', 'vendor payment'],
                'topics' => ['payment', 'pay vendor'],
                'answer' => "To record a vendor payment:\n1. Open **Vendors** → select the vendor.\n2. Use the payment/advance actions on the vendor account tabs.\n3. Choose payment type (invoice payment vs advance) carefully.\nAdvances do not reduce outstanding until adjusted against invoices.",
                'module' => 'payments',
                'route' => '/vendors',
                'permission' => 'payments.create',
            ],
            [
                'id' => 'help.expenses',
                'questions' => [
                    'where can i see expense reports', 'how to see expenses', 'expense report', 'expenses',
                ],
                'keywords' => ['expense', 'expenses'],
                'topics' => ['expense', 'expenses'],
                'answer' => "Expenses:\n- List: **Expenses** in the sidebar (or under a project).\n- Summary report: **Reports** → **Expense summary**.\nImport can create draft expenses only; approval stays in the normal workflow.",
                'module' => 'expenses',
                'route' => '/reports',
                'permission' => 'expenses.view',
            ],
            [
                'id' => 'help.download_report',
                'questions' => [
                    'how do i download a report', 'how to download report', 'how to export report',
                    'export report', 'excel report', 'download report',
                ],
                'keywords' => ['download', 'export report'],
                'topics' => ['download report', 'export report'],
                'answer' => "To download a report:\n1. Open **Reports**.\n2. Choose a report.\n3. Set filters, then use **Export Excel** / PDF if you have `reports.export` / print rights.",
                'module' => 'reports',
                'route' => '/reports',
                'permission' => 'reports.view',
            ],
            [
                'id' => 'help.po_rate',
                'questions' => [
                    'how do i change a po rate', 'how to change po rate', 'how to amend po rate',
                    'amend po rate', 'change purchase order rate', 'change po rate',
                ],
                'keywords' => ['rate', 'amendment', 'po rate'],
                'topics' => ['po rate', 'rate amendment'],
                'answer' => "PO rates:\n- **Draft PO**: edit rates on the PO form (needs `purchase_orders.edit_rates`).\n- **Approved PO**: use **Request rate amendment** — rates change only after approval. You cannot silently overwrite approved rates.",
                'module' => 'purchase_orders',
                'route' => '/projects',
                'permission' => 'purchase_orders.view',
            ],
            [
                'id' => 'help.work_order',
                'questions' => [
                    'how to create work order', 'how do i create a work order', 'create work order',
                    'how to make wo', 'new work order',
                ],
                'keywords' => ['work order', 'wo', 'contractor'],
                'topics' => ['work order', 'wo', 'contractor'],
                'answer' => "To create a work order:\n1. Open a **Project** → **Work Orders**.\n2. Click **+ New Work Order**.\n3. Select contractor/vendor and add quantities/rates.\n4. Save, then submit for approval per your permissions.",
                'module' => 'work_orders',
                'route' => '/projects',
                'permission' => 'work_orders.create',
            ],
            [
                'id' => 'help.create_user',
                'questions' => [
                    'how to create a user', 'how do i create a user', 'add user', 'create user',
                    'how to add staff', 'assign a role',
                ],
                'keywords' => ['user', 'staff', 'role'],
                'topics' => ['user', 'users', 'role'],
                'answer' => "To create a staff user:\n1. Open **Users** (Admin section).\n2. Click **Create user**.\n3. Enter name, email, and password.\n4. Assign one or more **staff roles** (Project Manager, Purchase Manager, Storekeeper, Accounts, and so on).\n5. The form shows the **sidebar menus** that role can use.\n6. Set project access (all projects or selected only), then Save.\n\nDo **not** assign Super Admin or Administrator for normal staff. Those roles skip the menu tutorial because they can see every screen. Use a staff role so the person only gets the menus for their job.",
                'module' => 'users',
                'route' => '/users/create',
                'permission' => 'users.create',
            ],
        ];
    }

    /**
     * @return array{entry: ?array<string, mixed>, score: float}
     */
    public function match(string $normalizedMessage): array
    {
        $best = null;
        $bestScore = 0.0;
        $messageTopics = $this->detectTopics($normalizedMessage);

        foreach ($this->entries() as $entry) {
            $score = 0.0;
            foreach ($entry['questions'] as $question) {
                if ($normalizedMessage === $question) {
                    $score = 1.0;
                    break;
                }
                if (str_contains($normalizedMessage, $question) || str_contains($question, $normalizedMessage)) {
                    $score = max($score, 0.95);
                }
                similar_text($normalizedMessage, $question, $percent);
                if ($percent >= 72) {
                    $score = max($score, $percent / 100 * 0.9);
                }
            }

            $topicHits = 0;
            foreach ($entry['topics'] ?? [] as $topic) {
                $topic = strtolower($topic);
                if ($topic === '') {
                    continue;
                }
                if (strlen($topic) <= 3) {
                    if (preg_match('/\b'.preg_quote($topic, '/').'\b/', $normalizedMessage)) {
                        $topicHits++;
                    }
                } elseif (str_contains($normalizedMessage, $topic)) {
                    $topicHits++;
                }
            }

            $keywordHits = 0;
            foreach ($entry['keywords'] as $keyword) {
                $keyword = strtolower($keyword);
                if ($keyword === '') {
                    continue;
                }
                if (strlen($keyword) <= 3) {
                    if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $normalizedMessage)) {
                        $keywordHits++;
                    }
                } elseif (str_contains($normalizedMessage, $keyword)) {
                    $keywordHits++;
                }
            }

            if ($topicHits > 0) {
                $score = max($score, min(0.92, 0.55 + ($topicHits * 0.2)));
            } elseif ($keywordHits > 0) {
                $score = max($score, min(0.7, 0.3 + ($keywordHits * 0.15)));
            }

            // Strong boost only when the entry's topic appears in the question.
            if ($topicHits > 0 && preg_match('/\b(how|create|add|make|new|record|where|see|view|check)\b/', $normalizedMessage)) {
                $score = max($score, 0.88);
            }

            // If the user named a clear topic, penalize entries that don't share it.
            if ($messageTopics !== [] && $topicHits === 0) {
                $score *= 0.35;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }

        return ['entry' => $bestScore >= 0.55 ? $best : null, 'score' => $bestScore];
    }

    /**
     * @return list<string>
     */
    private function detectTopics(string $normalizedMessage): array
    {
        $catalog = [
            'inventory', 'material', 'materials', 'stock', 'vendor', 'supplier', 'project',
            'boq', 'pr', 'purchase request', 'purchase requisition', 'po', 'purchase order',
            'grn', 'invoice', 'payment', 'expense', 'expenses', 'work order', 'wo',
            'import', 'excel', 'report', 'user', 'users', 'role',
        ];

        $found = [];
        foreach ($catalog as $topic) {
            if (strlen($topic) <= 3) {
                if (preg_match('/\b'.preg_quote($topic, '/').'\b/', $normalizedMessage)) {
                    $found[] = $topic;
                }
            } elseif (str_contains($normalizedMessage, $topic)) {
                $found[] = $topic;
            }
        }

        return $found;
    }
}
