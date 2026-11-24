@extends('admin::gabarits.application')
@section('titre', $bien->libelle)
@section('topbar_titre', 'Immobilisation · ' . $bien->code)

@section('contenu')
@use(App\Modules\Admin\Modeles\Immobilisation)

<div class="page-header">
    <div>
        <h1><i class="fas fa-building-columns"></i> {{ $bien->libelle }}</h1>
        <p style="font-family:monospace;">{{ $bien->code }} · {{ $bien->compte_immobilisation }} · {{ $bien->pointDeVente?->nom ?? 'Tous sites' }}</p>
    </div>
    <div>
        <a href="{{ route('admin.immobilisations.index') }}" class="btn"><i class="fas fa-arrow-left"></i> Retour</a>
    </div>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:22px;">
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Valeur brute</div>
        <div style="font-size:20px; font-weight:800; margin-top:6px;">{{ number_format($bien->valeur_acquisition, 0, ',', ' ') }} F</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Amorti</div>
        <div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--warning);">{{ number_format($bien->cumulAmorti(), 0, ',', ' ') }} F</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Valeur nette</div>
        <div style="font-size:20px; font-weight:800; margin-top:6px; color:var(--primary);">{{ number_format($bien->valeurNette(), 0, ',', ' ') }} F</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px; color:var(--text-3); text-transform:uppercase;">Mise en service</div>
        <div style="font-size:20px; font-weight:800; margin-top:6px;">{{ $bien->date_mise_en_service->format('d/m/Y') }}</div>
        <div style="font-size:11.5px; color:var(--text-3); margin-top:2px;">{{ $bien->duree_mois }} mois, linéaire</div>
    </div>
</div>

@if($bien->estSorti())
    <div style="margin-bottom:22px; padding:14px 18px; background:var(--bg3); border-radius:10px; color:var(--text-2);">
        <i class="fas fa-circle-info"></i>
        {{ $bien->statut === Immobilisation::CEDE ? 'Bien cédé' : 'Bien mis au rebut' }}
        le {{ $bien->date_sortie->format('d/m/Y') }}@if($bien->prix_cession) pour {{ number_format($bien->prix_cession, 0, ',', ' ') }} F@endif.
        Sa valeur comptable nette est partie en charge sur le compte 810000, et le bien est sorti du bilan.
    </div>
@endif

<div class="card" style="margin-bottom:22px;">
    <div class="card-header" style="border-bottom:1px solid var(--border); padding-bottom:12px;">
        <h2 style="font-size:15px; font-weight:700;"><i class="fas fa-table-list"></i> Plan d'amortissement</h2>
        <p style="font-size:12.5px; color:var(--text-3); margin-top:4px;">
            Calculé d'avance, prorata temporis à partir de la mise en service. Une dotation ne se passe qu'une fois :
            la repasser doublerait la charge et amortirait le bien au double de sa valeur.
        </p>
    </div>
    <div class="table-wrap" style="padding-top:10px;">
        <table>
            <thead>
                <tr>
                    <th>Exercice</th>
                    <th>Période</th>
                    <th>Dotation</th>
                    <th>Cumul</th>
                    <th>Valeur nette</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bien->dotations as $ligne)
                    <tr>
                        <td style="font-weight:700;">{{ $ligne->annee }}</td>
                        <td style="font-size:12px; color:var(--text-3);">
                            {{ $ligne->date_debut->format('d/m/Y') }} → {{ $ligne->date_fin->format('d/m/Y') }}
                        </td>
                        <td>{{ number_format($ligne->dotation, 0, ',', ' ') }}</td>
                        <td>{{ number_format($ligne->cumul, 0, ',', ' ') }}</td>
                        <td>{{ number_format($ligne->valeur_nette, 0, ',', ' ') }}</td>
                        <td>
                            @if($ligne->estComptabilisee())
                                <span style="color:var(--success); font-weight:600;">
                                    <i class="fas fa-circle-check"></i> Passée le {{ $ligne->comptabilise_at->format('d/m/Y') }}
                                </span>
                            @elseif($bien->estSorti())
                                <span style="color:var(--text-3);">—</span>
                            @else
                                <form method="POST" action="{{ route('admin.immobilisations.dotation', $ligne) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn" style="padding:4px 10px; font-size:12px;">
                                        Passer en comptabilité
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center; padding:30px; color:var(--text-3); font-style:italic;">
                            Aucune dotation : ce bien ne s'amortit pas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if(!$bien->estSorti())
<div class="card" style="padding:22px;">
    <h2 style="font-size:15px; font-weight:700; margin-bottom:6px;">Sortir le bien du bilan</h2>
    {{-- La dotation de l'exercice de sortie est due jusqu'au jour de la sortie :
         le bien a servi. L'omettre gonflerait la valeur nette, donc minorerait
         la charge et majorerait la plus-value, sur laquelle l'entreprise serait
         imposée. --}}
    <p style="font-size:12.5px; color:var(--text-3); margin-bottom:16px;">
        La dotation de l'exercice est passée jusqu'au jour de la sortie — le bien a servi. Sans prix,
        c'est un rebut : la valeur nette part en charge et rien n'entre en face.
    </p>

    <form method="POST" action="{{ route('admin.immobilisations.ceder', $bien) }}"
          onsubmit="return confirm('Sortir définitivement ce bien du bilan ?');">
        @csrf
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; align-items:end;">
            <div class="form-group">
                <label class="form-label">Date de sortie</label>
                <input type="date" name="date_sortie" class="form-control"
                       min="{{ $bien->date_mise_en_service->format('Y-m-d') }}"
                       value="{{ now()->toDateString() }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Prix de cession</label>
                <input type="number" step="0.01" min="0" name="prix_cession" class="form-control" value="0">
                <small style="color:var(--text-3); font-size:11px;">Zéro pour un rebut.</small>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-right-from-bracket"></i> Sortir du bilan
                </button>
            </div>
        </div>
    </form>
</div>
@endif
@endsection
