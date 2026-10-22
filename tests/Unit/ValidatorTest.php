<?php
namespace KdvLeads\Tests\Unit;

use KdvLeads\Http\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private function estimation(array $override = []): array
    {
        return $override + [
            'immatriculation' => 'ab123cd', 'marque' => 'BMW', 'modele' => 'Série 3', 'finition' => 'M Sport',
            'annee_mise_en_circulation' => '2020', 'kilometrage' => '65 000', 'motorisation' => 'diesel', 'boite' => 'automatique',
            'etat_general' => 'bon', 'etat_habitacle' => 'propre', 'accident' => 'aucun', 'suivi_entretien' => 'complet',
            'equipements' => ['gps', 'hack'], 'delai_vente' => 'asap', 'nom_complet' => 'Jean Dupont',
            'telephone' => '06 12 34 56 78', 'email' => '', 'consentement' => '1',
        ];
    }

    public function testValidEstimationIsCleaned(): void
    {
        [$errors, $c] = Validator::estimation($this->estimation());
        $this->assertSame([], $errors);
        $this->assertSame('AB-123-CD', $c['immatriculation']);
        $this->assertSame('+33612345678', $c['telephone']);
        $this->assertSame(65000, $c['kilometrage']);
        $this->assertSame(['gps'], $c['equipements'], 'unknown equipment keys are dropped');
        $this->assertSame('', $c['email'], 'e-mail is optional');
    }

    public function testRequiredFieldsAreEnforcedServerSide(): void
    {
        [$errors] = Validator::estimation([
            'marque' => '', 'annee_mise_en_circulation' => '1800', 'kilometrage' => '1 000 000', 'motorisation' => 'vapeur',
            'etat_general' => '', 'delai_vente' => '', 'nom_complet' => 'J', 'telephone' => '12', 'consentement' => '',
        ] + $this->estimation());
        foreach (['marque', 'annee_mise_en_circulation', 'kilometrage', 'motorisation', 'etat_general', 'delai_vente', 'nom_complet', 'telephone', 'consentement'] as $field) {
            $this->assertArrayHasKey($field, $errors, "$field must be rejected");
        }
    }

    public function testUnknownModelIsRejectedButOtherBrandAllowsFreeText(): void
    {
        [$errors] = Validator::estimation($this->estimation(['modele' => 'Zzz']));
        $this->assertArrayHasKey('modele', $errors);

        [$errors, $c] = Validator::estimation($this->estimation(['marque' => 'autre', 'marque_autre' => 'Lotus', 'modele_autre' => 'Elise']));
        $this->assertSame([], $errors);
        $this->assertSame('Lotus', $c['marque']);
        $this->assertSame('Elise', $c['modele']);
    }

    public function testInvalidOptionalEmailAndPlate(): void
    {
        [$errors] = Validator::estimation($this->estimation(['email' => 'pas-un-mail', 'immatriculation' => 'XXXX']));
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('immatriculation', $errors);
    }

    /** @dataProvider phones */
    public function testPhoneNormalisation(string $in, bool $foreign, ?string $expected): void
    {
        $this->assertSame($expected, Validator::normalize_phone($in, $foreign));
    }

    public static function phones(): array
    {
        return [
            'mobile'         => ['06 12 34 56 78', false, '+33612345678'],
            'landline dots'  => ['01.45.67.89.01', false, '+33145678901'],
            '+33'            => ['+33 6 12 34 56 78', false, '+33612345678'],
            '0033'           => ['0033612345678', false, '+33612345678'],
            'too short'      => ['0612', false, null],
            'foreign off'    => ['+44 7700 900123', false, null],
            'foreign on'     => ['+44 7700 900123', true, '+447700900123'],
            'letters'        => ['abc', true, null],
        ];
    }

    /** @dataProvider plates */
    public function testPlateNormalisation(string $in, ?string $expected): void
    {
        $this->assertSame($expected, Validator::normalize_plate($in));
    }

    public static function plates(): array
    {
        return [
            'SIV compact' => ['ab123cd', 'AB-123-CD'],
            'SIV spaced'  => ['AB 123 CD', 'AB-123-CD'],
            'SIV hyphens' => ['AB-123-CD', 'AB-123-CD'],
            'FNI'         => ['123 ABC 45', '123-ABC-45'],
            'forbidden letters I/O/U' => ['AI-123-OU', null],
            'garbage'     => ['hello', null],
        ];
    }

    public function testRechercheRequiresBudgetMaxAndContact(): void
    {
        [$errors] = Validator::recherche(['marques_modeles' => [], 'telephone' => 'x', 'email' => '', 'code_postal' => '75', 'consentement' => '']);
        foreach (['marques_modeles', 'budget_max', 'prenom', 'nom', 'telephone', 'email', 'code_postal', 'consentement'] as $field) {
            $this->assertArrayHasKey($field, $errors, "$field must be rejected");
        }
    }

    public function testRechercheValidAndCleaned(): void
    {
        [$errors, $c] = Validator::recherche([
            'marques_modeles' => ['Porsche Cayman', 'porsche cayman', 'BMW M2'], 'budget_min' => '60 000', 'budget_max' => '90 000',
            'carburant' => ['essence', 'nuke'], 'liens_annonces' => ['https://example.com/a'], 'prenom' => 'Camille', 'nom' => 'Durand',
            'telephone' => '0611223344', 'email' => 'camille@example.com', 'code_postal' => '75008', 'consentement' => '1',
        ]);
        $this->assertSame([], $errors);
        $this->assertSame(['Porsche Cayman', 'BMW M2'], $c['marques_modeles'], 'duplicates (case-insensitive) are merged');
        $this->assertSame(['essence'], $c['carburant']);
        $this->assertSame(90000, $c['budget_max']);
    }

    public function testRechercheRejectsBadLinksAndInvertedBudget(): void
    {
        [$errors] = Validator::recherche([
            'marques_modeles' => ['BMW'], 'budget_min' => '100 000', 'budget_max' => '50 000', 'liens_annonces' => ['javascript:alert(1)'],
            'prenom' => 'Camille', 'nom' => 'Durand', 'telephone' => '0611223344', 'email' => 'c@example.com', 'code_postal' => '75008', 'consentement' => '1',
        ]);
        $this->assertArrayHasKey('budget_min', $errors);
        $this->assertArrayHasKey('liens_annonces', $errors);
    }

    public function testSourceIsWhitelisted(): void
    {
        $s = Validator::source(['source' => ['utm_source' => 'google', 'gclid' => 'abc', 'evil' => 'x']]);
        $this->assertSame('google', $s['utm_source']);
        $this->assertSame(1, $s['gclid']);
        $this->assertArrayNotHasKey('evil', $s);
    }
}
