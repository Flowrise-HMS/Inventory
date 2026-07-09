<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Core\Models\Branch;
use Modules\Inventory\Classes\Services\DocumentNumberingService;
use Tests\TestCase;

class DocumentNumberingServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateModules(['Core', 'Inventory']);
    }

    public function test_generates_sequential_numbers_with_correct_format(): void
    {
        $branch = Branch::factory()->create();
        $service = app(DocumentNumberingService::class);

        $number1 = $service->generate('PO', $branch->id);
        $number2 = $service->generate('PO', $branch->id);

        $datePart = now()->format('Ymd');

        $this->assertMatchesRegularExpression(
            '/^PO-'.$datePart.'-\d{4}$/',
            $number1
        );

        $this->assertMatchesRegularExpression(
            '/^PO-'.$datePart.'-\d{4}$/',
            $number2
        );

        $seq1 = (int) substr($number1, -4);
        $seq2 = (int) substr($number2, -4);

        $this->assertEquals(1, $seq1);
        $this->assertEquals(2, $seq2);
    }

    public function test_generates_unique_numbers_per_prefix(): void
    {
        $branch = Branch::factory()->create();
        $service = app(DocumentNumberingService::class);

        $poNumber = $service->generate('PO', $branch->id);
        $reqNumber = $service->generate('REQ', $branch->id);

        $this->assertNotEquals($poNumber, $reqNumber);
        $this->assertStringStartsWith('PO-', $poNumber);
        $this->assertStringStartsWith('REQ-', $reqNumber);
    }

    public function test_generates_unique_numbers_per_branch(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $service = app(DocumentNumberingService::class);

        $number1 = $service->generate('PO', $branch1->id);
        $number2 = $service->generate('PO', $branch2->id);

        $datePart = now()->format('Ymd');

        $this->assertSame('PO-'.$datePart.'-0001', $number1);
        $this->assertSame('PO-'.$datePart.'-0001', $number2);
    }
}
