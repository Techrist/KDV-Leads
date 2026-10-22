<?php
namespace KdvLeads\Tests\Unit;

use KdvLeads\Admin\CsvExporter;
use KdvLeads\Database\EmailQueueRepository;
use KdvLeads\Database\ReferenceGenerator;
use KdvLeads\FormOptions;
use KdvLeads\LeadStatuses;
use KdvLeads\Mail\MessageBuilder;
use KdvLeads\Pdf\PdfGenerator;
use PHPUnit\Framework\TestCase;

final class MiscTest extends TestCase
{
    public function testReferenceFormat(): void
    {
        $this->assertSame('KDV-EST-20261009-0001', ReferenceGenerator::format('EST', '20261009', 1));
        $this->assertSame('KDV-REC-20261009-0042', ReferenceGenerator::format('REC', '20261009', 42));
        $this->assertSame('KDV-EST-20261009-12345', ReferenceGenerator::format('EST', '20261009', 12345), 'never truncates past 9999');
    }

    public function testEmailBackoffSchedule(): void
    {
        // 5 min, 15 min, 1 h, 3 h, 12 h, 24 h, then abandoned.
        $this->assertSame([300, 900, 3600, 10800, 43200, 86400], array_map([EmailQueueRepository::class, 'backoff'], [1, 2, 3, 4, 5, 6]));
        $this->assertNull(EmailQueueRepository::backoff(7));
    }

    public function testPdfFilenames(): void
    {
        $est = ['type' => 'estimation', 'reference' => 'KDV-EST-20261009-0001', 'payload' => json_encode(['marque' => 'BMW', 'modele' => 'Série 3'])];
        $this->assertSame('KDV_ESTIMATION_20261009_0001_BMW_Serie-3.pdf', PdfGenerator::filename($est));

        $rec = ['type' => 'recherche', 'reference' => 'KDV-REC-20261009-0007', 'payload' => json_encode(['marques_modeles' => ['Porsche Cayman', 'BMW M2']])];
        $this->assertSame('KDV_RECHERCHE_20261009_0007_PORSCHE_Cayman.pdf', PdfGenerator::filename($rec));

        $odd = ['type' => 'estimation', 'reference' => 'KDV-EST-20261009-0002', 'payload' => json_encode(['marque' => '../../etc', 'modele' => ''])];
        $this->assertMatchesRegularExpression('/^KDV_ESTIMATION_20261009_0002_[A-Z0-9-]+_[A-Za-z0-9-]+\.pdf$/', PdfGenerator::filename($odd), 'ASCII slug only, no path characters');
    }

    public function testAdminSubjectEstimation(): void
    {
        $lead = [
            'type' => 'estimation', 'sale_timeframe' => 'asap', 'vehicle_year' => 2020, 'mileage' => 65000, 'full_name' => "Jean\nDupont",
            'payload' => json_encode(['marque' => 'BMW', 'modele' => 'Série 3']),
        ];
        $this->assertSame('🔴 [KDV ESTIMATION] BMW Série 3 | 2020 | 65 000 km | Dès que possible | Jean Dupont', MessageBuilder::admin_subject($lead));

        $lead['sale_timeframe'] = '3_mois';
        $this->assertStringStartsWith('[KDV ESTIMATION] BMW', MessageBuilder::admin_subject($lead), 'no red dot unless "dès que possible"');
    }

    public function testAdminSubjectRecherche(): void
    {
        $lead = ['type' => 'recherche', 'budget_max' => 90000, 'full_name' => 'Camille Durand', 'payload' => json_encode(['marques_modeles' => ['Porsche Cayman', 'BMW M2']])];
        $this->assertSame('[KDV RECHERCHE] Porsche Cayman (+1) | Budget 90 000 € | Camille Durand', MessageBuilder::admin_subject($lead));
    }

    public function testCsvFormulaInjectionIsNeutralised(): void
    {
        foreach (['=1+1', '+33612345678', '-5', '@SUM(A1)'] as $risky) {
            $this->assertSame("'" . $risky, CsvExporter::cell($risky));
        }
        $this->assertSame('Jean Dupont', CsvExporter::cell('Jean Dupont'));
        $this->assertSame('', CsvExporter::cell(null));
    }

    public function testSaleTimeframePriorityOrder(): void
    {
        $ranks = array_map(static fn ($d) => $d[2], FormOptions::delais());
        $this->assertSame(['asap', '1_mois', '3_mois', 'renseigne'], array_keys(FormOptions::delais()));
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, array_values($ranks), 'ranks increase from most to least urgent');
    }

    public function testStatuses(): void
    {
        $this->assertArrayHasKey('a_rappeler', LeadStatuses::for_type('estimation'));
        $this->assertArrayNotHasKey('a_rappeler', LeadStatuses::for_type('recherche'));
        $this->assertSame('RDV', LeadStatuses::label('estimation', 'rdv'));
        $this->assertContains('perdu', LeadStatuses::closed());
    }

    public function testEstimationOptionsMatchClientTexts(): void
    {
        $this->assertCount(4, FormOptions::etat_general());
        $this->assertSame('Véhicule jamais accidenté.', FormOptions::accident()['aucun'][1]);
        $this->assertSame(['gps', 'clim_auto', 'toit_ouvrant', 'cuir', 'camera_recul', 'audio_premium'], array_keys(FormOptions::equipements()));
    }
}
