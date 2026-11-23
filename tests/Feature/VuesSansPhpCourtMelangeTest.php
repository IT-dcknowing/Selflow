<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Panne du 23/11/2026 : « Undefined variable $statutsFiltre » sur la liste des
 * factures de vente. Une directive `@php` courte, sur une ligne, dans une vue
 * qui contient aussi un bloc `@php … @endphp` : Blade prend la ligne pour le
 * début d'un bloc et avale tout jusqu'au `@endphp` suivant.
 */
class VuesSansPhpCourtMelangeTest extends TestCase
{
    public function test_aucune_vue_ne_melange_les_deux_formes_de_php(): void
    {
        $fautives = collect(File::allFiles(base_path('app/Modules')))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'))
            ->filter(function ($f) {
                $contenu = $f->getContents();

                return preg_match('/@php\s*\(/', $contenu) && str_contains($contenu, '@endphp');
            })
            ->map(fn ($f) => $f->getRelativePathname())
            ->values()
            ->all();

        $this->assertSame([], $fautives, 'Ces vues mélangent la forme courte et la forme bloc : utilisez la forme bloc.');
    }
}
