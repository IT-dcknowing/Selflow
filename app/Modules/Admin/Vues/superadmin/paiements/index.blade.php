@extends('admin::gabarits.application')
@use('App\Modules\Admin\Modeles\PaiementAbonnement', 'Paiement')
@use('App\Modules\Admin\Regles\Abonnements', 'Abonnements')
@use('App\Modules\Admin\Regles\PaiementParReference', 'Reference')
@section('titre', 'Paiements d\'abonnement')
@section('topbar_titre', 'SuperAdmin — Paiements d\'abonnement')

@section('contenu')
<div style="max-width:1200px; margin:0 auto;">
    <div class="card" style="padding:16px 20px; margin-bottom:16px; font-size:13px; color:var(--text-2); line-height:1.7;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        En attendant l'API de paiement, les entreprises déposent le montant sur le numéro du cabinet et saisissent la
        <strong>référence de la transaction</strong>. Vérifiez-la, puis <strong>confirmez et activez</strong> : l'échéance
        est repoussée d'autant de mois que payés, et une entreprise à terme est débloquée.
        <strong>{{ $ouverts }}</strong> paiement{{ $ouverts > 1 ? 's' : '' }} à traiter.
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
        <a href="{{ route('superadmin.paiements.index') }}" class="btn {{ $statut ? 'btn-outline' : 'btn-primary' }} btn-sm">Tous</a>
        @foreach(Paiement::STATUTS as $cle => $libelle)
            <a href="{{ route('superadmin.paiements.index', ['statut' => $cle]) }}" class="btn {{ $statut === $cle ? 'btn-primary' : 'btn-outline' }} btn-sm">{{ $libelle }}</a>
        @endforeach
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        @if($paiements->isEmpty())
            <p style="padding:30px; text-align:center; color:var(--text-3);">Aucun paiement.</p>
        @else
        <table class="table" style="width:100%;">
            <thead><tr><th>Reçu le</th><th>Entreprise</th><th>Offre</th><th>Montant</th><th>Paiement</th><th>Statut</th></tr></thead>
            <tbody>
            @foreach($paiements as $p)
                @php [$fond, $texte] = $p->couleurs(); @endphp
                <tr>
                    <td style="font-size:12.5px; white-space:nowrap;">{{ $p->created_at->format('d/m/Y H:i') }}</td>
                    <td><strong>{{ $p->entreprise?->nom }}</strong>
                        <div style="font-size:11.5px; color:var(--text-3);">{{ $p->utilisateur?->email }}</div>
                        <div style="font-size:11.5px; color:var(--text-3);">Échéance : {{ $p->entreprise?->abonnement_expire_le?->format('d/m/Y') ?? '—' }}</div></td>
                    <td><strong>{{ Abonnements::libelle($p->offre) }}</strong>
                        <div style="font-size:11.5px; color:var(--text-3);">{{ $p->nature === 'renouvellement' ? 'Renouvellement' : 'Souscription' }} · {{ $p->mois }} mois</div></td>
                    <td><strong>{{ number_format((float) $p->montant, 0, ',', ' ') }} F</strong></td>
                    <td style="font-size:12px;"><strong>{{ Reference::OPERATEURS[$p->operateur_paiement] ?? $p->operateur_paiement }}</strong>
                        <div style="font-family:ui-monospace,Menlo,monospace;">{{ $p->reference_paiement }}</div></td>
                    <td style="min-width:250px;">
                        <span style="font-size:11.5px; font-weight:700; padding:3px 8px; border-radius:20px; background:{{ $fond }}; color:{{ $texte }};">{{ $p->libelleStatut() }}</span>
                        <div style="margin-top:6px; font-size:11px; color:var(--text-3); line-height:1.6; border-left:2px solid var(--border); padding-left:8px;">
                            @foreach($p->etapes() as $etape)
                                <div><strong style="color:var(--text-2);">{{ $etape['libelle'] }}</strong> {{ $etape['le']?->format('d/m/Y H:i') }}</div>
                            @endforeach
                        </div>
                        @if($p->motif_echec)<div style="font-size:11.5px; color:#b91c1c; margin-top:4px;">Motif : {{ $p->motif_echec }}</div>@endif
                        @if($p->estOuvert())
                        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                            @if($p->statut === 'recu')
                            <form method="POST" action="{{ route('superadmin.paiements.traiter', $p) }}" style="margin:0;">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="en_cours">
                                <button class="btn btn-sm" style="background:#fef9c3; color:#a16207; border:1px solid #fde68a;">En cours</button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('superadmin.paiements.traiter', $p) }}" style="margin:0;"
                                  onsubmit="return confirm('Confirmer le paiement et activer l\'abonnement de {{ addslashes($p->entreprise?->nom ?? '') }} ?');">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="activer">
                                <button class="btn btn-sm" style="background:#16a34a; color:#fff; border:none;"><i class="fas fa-check"></i> Confirmer / Activer</button>
                            </form>
                            <form method="POST" action="{{ route('superadmin.paiements.traiter', $p) }}" style="margin:0;"
                                  onsubmit="var m = prompt('Motif de l\'échec (visible par l\'entreprise) ?'); if (!m) return false; this.motif_echec.value = m; return true;">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="echec">
                                <input type="hidden" name="motif_echec" value="">
                                <button class="btn btn-sm" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;">Échec</button>
                            </form>
                        </div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div style="padding:0 16px;">{{ $paiements->links() }}</div>
        @endif
    </div>
</div>
@endsection
