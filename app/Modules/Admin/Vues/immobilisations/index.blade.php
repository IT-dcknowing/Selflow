@extends('admin::gabarits.application')
@section('titre', 'Immobilisations')
@section('topbar_titre', 'Comptabilité · Immobilisations')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-building-columns"></i> Immobilisations</h1>
        <p>Les biens que l'entreprise possède et qui servent plus d'un exercice, et ce qu'ils portent d'amortissement.</p>
    </div>
    <div>
        {{-- Une entreprise qui migre a deja un parc : camions, fours,
             ordinateurs, chacun avec son anteriorite. Les ressaisir un a un est
             ce qui decide du sort de la migration. --}}
        <button type="button" class="btn" onclick="ouvrirImport('modalImportImmobilisations')">
            <i class="fas fa-file-import"></i> Importer un parc
        </button>
        <a href="{{ route('admin.immobilisations.creer') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> Nouveau bien
        </a>
    </div>
</div>

{{-- Ce que le bilan porte : brut, amortissement, net. C'est le tableau des
     immobilisations de la liasse. --}}
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:22px;">
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase; letter-spacing:.04em;">Valeur brute</div>
        <div style="font-size:22px; font-weight:800; margin-top:6px;">{{ number_format($valeurBrute, 0, ',', ' ') }} F</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase; letter-spacing:.04em;">Amortissements cumulés</div>
        <div style="font-size:22px; font-weight:800; margin-top:6px; color:var(--warning);">{{ number_format($cumul, 0, ',', ' ') }} F</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase; letter-spacing:.04em;">Valeur nette au bilan</div>
        <div style="font-size:22px; font-weight:800; margin-top:6px; color:var(--primary);">{{ number_format($valeurNette, 0, ',', ' ') }} F</div>
    </div>
</div>

{{-- **La charge que l'entreprise oublierait si personne ne clôturait.** Une
     entreprise qui n'amortit pas paie l'impôt sur un bénéfice qu'elle n'a pas. --}}
@if($dotationsDues > 0)
<div style="margin-bottom:22px; padding:16px 18px; background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; color:#92400e; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
    <div style="display:flex; align-items:center; gap:12px;">
        <i class="fas fa-triangle-exclamation" style="font-size:18px;"></i>
        <div>
            <strong>{{ number_format($dotationsDues, 0, ',', ' ') }} F de dotations restent à passer pour {{ $annee }}.</strong>
            <div style="font-size:12.5px; margin-top:2px;">
                C'est une charge déductible : ne pas la passer revient à payer l'impôt sur un bénéfice que vous n'avez pas.
            </div>
        </div>
    </div>
    <form method="POST" action="{{ route('admin.immobilisations.cloturer') }}">
        @csrf
        <input type="hidden" name="annee" value="{{ $annee }}">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-calendar-check"></i> Passer les dotations {{ $annee }}
        </button>
    </form>
</div>
@endif

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Bien</th>
                    <th>Mise en service</th>
                    <th>Valeur brute</th>
                    <th>Amorti</th>
                    <th>Valeur nette</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                @forelse($biens as $bien)
                    <tr>
                        <td style="font-family:monospace;">
                            <a href="{{ route('admin.immobilisations.fiche', $bien) }}">{{ $bien->code }}</a>
                        </td>
                        <td>
                            <strong>{{ $bien->libelle }}</strong>
                            <div style="font-size:11px; color:var(--text-3);">
                                {{ $bien->compte_immobilisation }} · {{ $bien->pointDeVente?->nom }}
                            </div>
                        </td>
                        <td>{{ $bien->date_mise_en_service->format('d/m/Y') }}</td>
                        <td>{{ number_format($bien->valeur_acquisition, 0, ',', ' ') }}</td>
                        <td style="color:var(--warning);">{{ number_format($bien->cumulAmorti(), 0, ',', ' ') }}</td>
                        <td style="font-weight:700;">{{ number_format($bien->valeurNette(), 0, ',', ' ') }}</td>
                        <td>
                            @if($bien->statut === \App\Modules\Admin\Modeles\Immobilisation::EN_SERVICE)
                                <span style="color:var(--success); font-weight:600;">En service</span>
                            @elseif($bien->statut === \App\Modules\Admin\Modeles\Immobilisation::CEDE)
                                <span style="color:var(--text-3);">Cédé le {{ $bien->date_sortie?->format('d/m/Y') }}</span>
                            @else
                                <span style="color:var(--danger);">Rebuté le {{ $bien->date_sortie?->format('d/m/Y') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center; padding:40px; color:var(--text-3); font-style:italic;">
                            Aucun bien immobilisé. Un camion, un four, un ordinateur : tout ce qui sert plus d'un exercice a sa place ici.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding:14px;">{{ $biens->links() }}</div>
</div>
@include('admin::composants.modal-import', [
    'type'  => 'immobilisations',
    'label' => 'Immobilisations',
    'id'    => 'modalImportImmobilisations',
])
@endsection
