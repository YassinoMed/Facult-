<?php

namespace Database\Seeders;

use App\Models\LieuNaissance;
use Illuminate\Database\Seeder;

/**
 * Correspondance contrôlée des lieux de naissance tunisiens (§14).
 * Données de RÉFÉRENCE (aucun credential) : seedable en production.
 * Extensible administrativement : toute nouvelle ligne dans
 * `lieux_naissance` est immédiatement utilisée par le scan.
 */
class LieuNaissanceSeeder extends Seeder
{
    public function run(): void
    {
        $lieux = [
            // [arabe, latin, officiel]
            ['تونس', 'Tunis', 'Tunis'],
            ['صفاقس', 'Sfax', 'Sfax'],
            ['سوسة', 'Sousse', 'Sousse'],
            ['بنزرت', 'Bizerte', 'Bizerte'],
            ['نابل', 'Nabeul', 'Nabeul'],
            ['القيروان', 'Kairouan', 'Kairouan'],
            ['قابس', 'Gabes', 'Gabès'],
            ['قفصة', 'Gafsa', 'Gafsa'],
            ['توزر', 'Tozeur', 'Tozeur'],
            ['المهدية', 'Mahdia', 'Mahdia'],
            ['المنستير', 'Monastir', 'Monastir'],
            ['أريانة', 'Ariana', 'Ariana'],
            ['باجة', 'Beja', 'Béja'],
            ['جندوبة', 'Jendouba', 'Jendouba'],
            ['الكاف', 'Le Kef', 'Le Kef'],
            ['تطاوين', 'Tataouine', 'Tataouine'],
            ['مدنين', 'Medenine', 'Médenine'],
            ['زغوان', 'Zaghouan', 'Zaghouan'],
            ['سيدي بوزيد', 'Sidi Bouzid', 'Sidi Bouzid'],
            ['الرديف', 'Redeyef', 'Redeyef'],
        ];

        foreach ($lieux as [$arabe, $latin, $officiel]) {
            LieuNaissance::enregistrer($arabe, $latin, $officiel);
        }
    }
}
