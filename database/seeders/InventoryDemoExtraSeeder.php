<?php

namespace Database\Seeders;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Vendor;

/**
 * Adds a second wave of GRNs and material issues on top of InventoryDemoSeeder.
 *
 * php artisan db:seed --class=InventoryDemoExtraSeeder --force
 */
class InventoryDemoExtraSeeder extends InventoryDemoSeeder
{
    private const SKYLINE_BOQ = 'Substructure & Compound Wall Materials';

    private const MATERIALS = [
        'cement' => 'Cement OPC 53',
        'tmt12' => 'TMT Bar Fe500D 12mm',
        'tmt8' => 'TMT Bar Fe500D 8mm',
        'wire' => 'Binding Wire 18 SWG',
        'sand' => 'River Sand',
        'aggregate' => 'Coarse Aggregate 20mm',
        'bricks' => 'Red Clay Bricks',
        'aac' => 'AAC Block 600x200x150',
        'waterproofing' => 'Waterproofing Compound',
        'conduit' => 'PVC Conduit Pipe 25mm',
        'putty' => 'Wall Putty',
        'plywood' => 'Shuttering Plywood',
    ];

    private const RIVERSIDE_BOQ_ITEMS = [
        'cement' => 'Supply of OPC 53 grade cement',
        'tmt12' => 'Supply of TMT Fe500D 12mm bars',
        'tmt8' => 'Supply of TMT Fe500D 8mm bars',
        'wire' => 'Supply of binding wire 18 SWG',
        'sand' => 'Supply of Zone II river sand',
        'aggregate' => 'Supply of 20mm coarse aggregate',
        'bricks' => 'Supply of first class red clay bricks',
        'aac' => 'Supply of AAC blocks 600x200x150',
        'waterproofing' => 'Supply of integral waterproofing compound',
        'conduit' => 'Supply of 25mm PVC conduit pipe',
    ];

    public function run(): void
    {
        $riverside = Project::query()->where('project_code', self::MAIN_CODE)->first();
        $skyline = Project::query()->where('project_code', self::SECOND_CODE)->first();

        if (! $riverside || ! $skyline) {
            $this->command?->warn('Run InventoryDemoSeeder first.');

            return;
        }

        if (Boq::query()->where('project_id', $skyline->id)->where('title', self::SKYLINE_BOQ)->exists()) {
            $this->command?->warn('Extra inventory demo data already exists; nothing to do.');

            return;
        }

        $this->seedWith(fn () => $this->extend($riverside, $skyline));
        $this->command?->info('Extra GRNs and material issues added to the demo projects.');
    }

    private function extend(Project $riverside, Project $skyline): void
    {
        foreach (self::MATERIALS as $key => $name) {
            $this->materials[$key] = Material::query()->where('name', $name)->latest('id')->firstOrFail();
        }

        $steel = Vendor::query()->where('name', 'Shree Ganesh Cement & Steel Traders')->firstOrFail();
        $aggregates = Vendor::query()->where('name', 'Narmada Aggregates & Bricks')->firstOrFail();
        $riversideItems = $this->riversideBoqItems($riverside);

        $this->at('2026-10-06');
        $this->boq($skyline, self::SKYLINE_BOQ, [
            'cement' => ['Supply of OPC 53 grade cement', 'Bag', 1500, 390],
            'tmt12' => ['Supply of TMT Fe500D 12mm bars', 'Kg', 20000, 63],
            'wire' => ['Supply of binding wire 18 SWG', 'Kg', 400, 86],
            'sand' => ['Supply of Zone II river sand', 'Cum', 200, 2850],
            'bricks' => ['Supply of first class red clay bricks', 'Nos', 50000, 9.2],
        ]);
        $skylineItems = $this->boqItems;

        $skySteel = $this->order($skyline, $steel, '2026-10-06', '2026-10-07', 'Cement and TMT steel for Skyline Plaza plinth', [
            'cement' => 600, 'tmt12' => 6000,
        ]);
        $skyAggregate = $this->order($skyline, $aggregates, '2026-10-06', '2026-10-07', 'Sand and bricks for Skyline Plaza compound wall', [
            'sand' => 60, 'bricks' => 15000,
        ]);

        $this->boqItems = $riversideItems;
        $rivSteel = $this->order($riverside, $steel, '2026-10-07', '2026-10-08', 'Cement and TMT steel for Tower A first floor', [
            'cement' => 1000, 'tmt12' => 12000, 'tmt8' => 6000, 'wire' => 300,
        ]);
        $rivAggregate = $this->existingOrder($riverside, 'Sand, aggregate and bricks for Tower A');
        $rivBlocks = $this->existingOrder($riverside, 'AAC blocks, waterproofing and conduits for Tower A superstructure');

        $this->grn($riverside, $rivAggregate, '2026-10-09', 'NA-1302', 'GJ18TT1302', [
            ['aggregate', 20, 20, 0, null],
            ['bricks', 10000, 9850, 150, '150 bricks chipped, returned to supplier'],
        ]);

        $this->boqItems = $skylineItems;
        $this->grn($skyline, $skySteel, '2026-10-09', 'CH-SG-4610', 'GJ01AB4610', [
            ['cement', 300, 300, 0, null],
            ['tmt12', 4000, 4000, 0, null],
        ]);
        $this->grn($skyline, $skyAggregate, '2026-10-09', 'NA-1305', 'GJ18TT1305', [
            ['sand', 40, 40, 0, null],
            ['bricks', 10000, 9800, 200, '200 bricks broken in transit'],
        ]);

        $this->boqItems = $riversideItems;
        $this->grn($riverside, $rivSteel, '2026-10-10', 'CH-SG-4622', 'GJ01AB4622', [
            ['cement', 500, 500, 0, null],
            ['tmt12', 6000, 6000, 0, null],
            ['wire', 150, 150, 0, null],
        ]);
        $this->issue($riverside, '2026-10-10', 'Ramesh Patel Constructions', 'First floor columns - Tower A', [
            'cement' => 250, 'tmt12' => 4500, 'tmt8' => 2500, 'wire' => 80, 'aggregate' => 30, 'sand' => 10,
        ]);
        $this->issue($skyline, '2026-10-10', 'Om Sai Constructions', 'Plinth beams - Block 1', [
            'cement' => 220, 'tmt12' => 3500, 'wire' => 20, 'sand' => 25,
        ]);

        $this->grn($riverside, $rivSteel, '2026-10-12', 'CH-SG-4655', 'GJ01AB4655', [
            ['cement', 300, 296, 4, '4 bags lumped, rejected at gate'],
            ['tmt12', 3000, 3000, 0, null],
            ['tmt8', 6000, 6000, 0, null],
        ]);
        $this->issue($riverside, '2026-10-12', 'Mahesh Masonry Works', 'First floor brickwork - Tower A', [
            'bricks' => 9000, 'cement' => 120, 'sand' => 8,
        ]);

        $this->boqItems = $skylineItems;
        $this->grn($skyline, $skySteel, '2026-10-12', 'CH-SG-4661', 'GJ01AB4661', [
            ['cement', 300, 300, 0, null],
            ['tmt12', 2000, 2000, 0, null],
        ]);
        $this->issue($skyline, '2026-10-12', 'Om Sai Constructions', 'Compound wall - north side', [
            'bricks' => 7000, 'cement' => 90, 'sand' => 12,
        ]);

        $this->at('2026-10-13');
        $pending = MaterialReceipt::query()
            ->where('purchase_order_id', $rivBlocks->id)
            ->where('status', MaterialReceipt::STATUS_SUBMITTED)
            ->firstOrFail();
        $this->http->post(route('projects.grns.post', [$riverside, $pending]));

        $this->boqItems = $riversideItems;
        $this->grn($riverside, $rivBlocks, '2026-10-13', 'BBS-9990', 'GJ27KK9990', [
            ['aac', 500, 500, 0, null],
            ['waterproofing', 80, 80, 0, null],
        ], post: false);
        $this->issue($riverside, '2026-10-13', 'Waterproof Pro Services', 'Terrace waterproofing - Tower A', [
            'waterproofing' => 30, 'cement' => 40,
        ]);
        $this->issue($riverside, '2026-10-13', 'Sparkline Electricals', 'First floor slab conduiting - Tower A', [
            'conduit' => 900,
        ]);
        $this->issue($riverside, '2026-10-13', 'Mahesh Masonry Works', 'First floor partition walls - Tower A', [
            'aac' => 1200,
        ]);

        $this->boqItems = $skylineItems;
        $this->grn($skyline, $skyAggregate, '2026-10-13', 'NA-1331', 'GJ18TT1331', [
            ['sand', 20, 20, 0, null],
            ['bricks', 5000, 5000, 0, null],
        ], post: false);
        $this->issue($skyline, '2026-10-13', 'Om Sai Constructions', 'Plinth filling - Block 1', [
            'sand' => 10,
        ], post: false);
    }

    /**
     * @return array<string, BoqItem>
     */
    private function riversideBoqItems(Project $project): array
    {
        $boq = Boq::query()
            ->where('project_id', $project->id)
            ->where('title', 'Tower A - Civil & Structural Materials')
            ->firstOrFail();

        return collect(self::RIVERSIDE_BOQ_ITEMS)
            ->map(fn (string $description) => BoqItem::query()
                ->where('boq_revision_id', $boq->current_revision_id)
                ->where('description', $description)
                ->firstOrFail())
            ->all();
    }

    private function existingOrder(Project $project, string $subject): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->where('subject', $subject)
            ->with('items')
            ->firstOrFail();
    }
}
