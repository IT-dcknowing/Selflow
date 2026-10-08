@extends('admin::gabarits.application')
@section('titre', 'Exercices antérieurs')
@section('topbar_titre', 'SuperAdmin — Comptabilité des exercices antérieurs')

@section('contenu')
<div style="max-width:1100px;margin:0 auto;">
    <div class="card" style="padding:18px 20px;margin-bottom:18px;font-size:13px;color:var(--text-2);line-height:1.7;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        Une entreprise qui facturait déjà dans Selflow demande la comptabilité de ses exercices passés.
        <strong>Cochez le ou les exercices que vous accordez</strong>, puis validez : seuls ceux-là partiront
        chez Comptaflow. L'exercice en cours n'a pas besoin d'accord.
    </div>

    @if(session('success'))<div class="alert alert-success" style="margin-bottom:14px;">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger" style="margin-bottom:14px;">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" style="margin-bottom:14px;">{{ $errors->first() }}</div>@endif

    <div class="card" style="padding:0;overflow:hidden;">
        <table class="table" style="width:100%;">
            <thead>
                <tr>
                    <th>Entreprise</th>
                    <th>Demandée le</th>
                    <th>Exercices</th>
                    <th>Statut</th>
                    <th style="text-align:right;">Décision</th>
                </tr>
            </thead>
            <tbody>
            @forelse($demandes as $demande)
                <tr data-demande="{{ $demande->id }}">
                    <td>
                        <strong>{{ $demande->entreprise?->nom ?? '—' }}</strong>
                        <div style="font-size:11.5px;color:var(--text-3);">NCC {{ $demande->entreprise?->ncc ?? '—' }}
                            @if($demande->demandeur) · par {{ $demande->demandeur->prenom }} {{ $demande->demandeur->nom }} @endif
                        </div>
                    </td>
                    <td style="white-space:nowrap;">{{ $demande->created_at->format('d/m/Y H:i') }}</td>
                    <td>
                        @if($demande->statut === \App\Modules\Admin\Modeles\DemandeExercicesAnterieurs::EN_ATTENTE)
                            <form id="valider-{{ $demande->id }}" method="POST" action="{{ route('superadmin.exercices_anterieurs.valider', $demande) }}">
                                @csrf
                                @foreach($demande->annees_demandees as $annee)
                                    <label style="display:inline-flex;align-items:center;gap:5px;margin-right:10px;font-weight:600;">
                                        <input type="checkbox" name="annees[]" value="{{ $annee }}" checked> {{ $annee }}
                                    </label>
                                @endforeach
                            </form>
                        @else
                            Demandés : {{ implode(', ', $demande->annees_demandees) }}
                            @if($demande->annees_accordees)
                                <br><strong style="color:#047857;">Accordés : {{ implode(', ', $demande->annees_accordees) }}</strong>
                            @endif
                        @endif
                    </td>
                    <td>
                        @switch($demande->statut)
                            @case('en_attente') <span class="badge" style="background:#fffbeb;color:#b45309;">En attente</span> @break
                            @case('validee') <span class="badge" style="background:#ecfdf5;color:#047857;">Validée</span> @break
                            @default <span class="badge" style="background:#fef2f2;color:#b91c1c;">Refusée</span>
                                @if($demande->motif_refus)<div style="font-size:11.5px;color:var(--text-3);margin-top:3px;">{{ $demande->motif_refus }}</div>@endif
                        @endswitch
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        @if($demande->statut === \App\Modules\Admin\Modeles\DemandeExercicesAnterieurs::EN_ATTENTE)
                            <button type="submit" form="valider-{{ $demande->id }}" class="btn btn-primary btn-sm">
                                <i class="fas fa-check"></i> Valider la sélection
                            </button>
                            <form method="POST" action="{{ route('superadmin.exercices_anterieurs.refuser', $demande) }}" style="display:inline;"
                                  onsubmit="const m = prompt('Motif du refus (facultatif) :'); if (m === null) return false; this.motif_refus.value = m; return true;">
                                @csrf
                                <input type="hidden" name="motif_refus" value="">
                                <button type="submit" class="btn btn-outline btn-sm" style="color:#b91c1c;">Refuser</button>
                            </form>
                        @else
                            <span style="font-size:12px;color:var(--text-3);">{{ $demande->traitee_at?->format('d/m/Y') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--text-3);padding:28px;">Aucune demande pour le moment.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:12px;">{{ $demandes->links() }}</div>
</div>
@endsection
