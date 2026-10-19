{{--
    Les modules fermés pour l'entreprise (19/10/2026).

    Un client cochait « Stock » pour un employé et ne voyait pas le volet
    apparaître : le module n'était pas ouvert pour le dossier. Une case ne
    peut ouvrir que ce que l'entreprise possède — on le dit ici, et on dit où
    l'ouvrir.
--}}
@php
    $__fermes = [];
    if ($entreprise ?? null) {
        foreach (['stock' => 'Stock', 'production' => 'Production', 'comptabilite' => 'Comptabilité'] as $__module => $__libelle) {
            if (! $entreprise->moduleEstActif($__module)) {
                $__fermes[] = $__libelle;
            }
        }
    }
@endphp
@if($__fermes)
<div class="modules-fermes" style="background:#fffbeb; border:1px solid #fcd34d; color:#92400e; border-radius:10px; padding:10px 14px; margin:0 0 12px; font-size:12.5px;">
    <i class="fas fa-circle-info"></i>
    Module(s) non ouvert(s) pour votre entreprise : <strong>{{ implode(', ', $__fermes) }}</strong>.
    Leurs droits ne s'accordent pas et leur volet n'apparaît dans le menu de personne.
    @if(auth()->user()?->estAdmin() && Route::has('admin.souscription.index'))
        Pour l'ouvrir : <a href="{{ route('admin.souscription.index') }}" style="color:#92400e; font-weight:700; text-decoration:underline;">Configuration de l'entreprise → Modules</a>, ou demandez-le au support.
    @else
        Demandez à l'administrateur de l'entreprise de l'ouvrir.
    @endif
</div>
@endif
