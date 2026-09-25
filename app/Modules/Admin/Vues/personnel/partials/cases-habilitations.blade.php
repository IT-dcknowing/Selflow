{{--
    Les cases d'habilitation, dressées depuis le catalogue.

    Elles étaient écrites en dur **deux fois** — dans l'écran de création et
    dans la fiche —, si bien qu'un droit ajouté d'un côté manquait de l'autre,
    et qu'aucun des deux ne regardait les modules du dossier.

    La vue appelante fournit :
      - `$accordees` : les droits déjà cochés (tableau de chaînes) ;
      - `$entreprise` : le dossier, pour ne proposer que ses modules.
--}}
@php
    $groupes  = \App\Modules\Authentification\Regles\Habilitations::pourLEntreprise($entreprise ?? null);
    $accordees = $accordees ?? [];
@endphp

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:18px;">
    @foreach($groupes as $titre => $droits)
    <div>
        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">
            {{ $titre }}
        </div>
        @foreach($droits as $droit => $libelle)
        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
            <input type="checkbox" name="habilitations[]" value="{{ $droit }}"
                   @if(in_array($droit, $accordees, true)) checked @endif>
            {{ $libelle }}
        </label>
        @endforeach
    </div>
    @endforeach
</div>
