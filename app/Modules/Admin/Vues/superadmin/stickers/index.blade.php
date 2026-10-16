@extends('admin::gabarits.application')
@use('App\Modules\Admin\Modeles\DemandeSticker', 'Demande')
@section('titre', 'Demandes de stickers')
@section('topbar_titre', 'SuperAdmin — Demandes de stickers')

@section('contenu')
<div style="max-width:1200px; margin:0 auto;">
    <div class="card" style="padding:18px 20px; margin-bottom:18px; font-size:13px; color:var(--text-2); line-height:1.7;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        Les entreprises demandent ici des stickers que <strong>DC-Knowing achète pour elles</strong> sur leur espace FNE.
        Chaque demande arrive aussi par courriel. Faites avancer le statut : l'entreprise le voit dans Selflow.
        <strong>{{ $ouvertes }}</strong> demande{{ $ouvertes > 1 ? 's' : '' }} en cours.
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
        <a href="{{ route('superadmin.stickers.index') }}" class="btn {{ $statut ? 'btn-outline' : 'btn-primary' }} btn-sm">Toutes</a>
        @foreach(Demande::STATUTS as $cle => $libelle)
            <a href="{{ route('superadmin.stickers.index', ['statut' => $cle]) }}" class="btn {{ $statut === $cle ? 'btn-primary' : 'btn-outline' }} btn-sm">{{ $libelle }}</a>
        @endforeach
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        @if($demandes->isEmpty())
            <p style="padding:30px; text-align:center; color:var(--text-3);">Aucune demande.</p>
        @else
        <table class="table" style="width:100%;">
            <thead><tr><th>Reçue le</th><th>Priorité</th><th>Entreprise</th><th>Quantité</th><th>Paiement</th><th>Site</th><th>Contact</th><th>Statut</th></tr></thead>
            <tbody>
            @foreach($demandes as $d)
                <tr>
                    <td style="font-size:12.5px; white-space:nowrap;">{{ $d->created_at->format('d/m/Y H:i') }}</td>
                    <td>
                        <span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; {{ $d->urgence === 'urgente' ? 'background:#fee2e2; color:#b91c1c;' : 'background:#f1f5f9; color:#475569;' }}">
                            {{ $d->urgence === 'urgente' ? 'Urgente' : 'Normale' }}
                        </span>
                    </td>
                    <td><strong>{{ $d->entreprise?->nom }}</strong>
                        <div style="font-size:11.5px; color:var(--text-3);">NCC {{ $d->entreprise?->ncc ?? '—' }} · solde {{ number_format((int) ($d->entreprise?->fne_sticker_balance ?? 0), 0, ',', ' ') }}</div></td>
                    <td><strong>{{ number_format($d->quantite, 0, ',', ' ') }}</strong>
                        <div style="font-size:11.5px; color:var(--text-3);">≈ {{ number_format((float) $d->montant_estime, 0, ',', ' ') }} F</div></td>
                    <td style="font-size:12px;">
                        @if($d->reference_paiement)
                            <strong>{{ \App\Modules\Admin\Regles\PaiementParReference::OPERATEURS[$d->operateur_paiement] ?? $d->operateur_paiement }}</strong>
                            <div style="font-family:ui-monospace,Menlo,monospace;">{{ $d->reference_paiement }}</div>
                        @else
                            <span style="color:var(--text-3);">—</span>
                        @endif
                    </td>
                    <td style="font-size:12.5px;">{{ $d->pointDeVente?->nom ?? 'Tous' }}</td>
                    <td style="font-size:12px;">{{ trim(($d->utilisateur?->prenom ?? '') . ' ' . ($d->utilisateur?->nom ?? '')) }}<br>
                        <span style="color:var(--text-3);">{{ $d->utilisateur?->email }}@if($d->contact_telephone) · {{ $d->contact_telephone }}@endif</span>
                        @if($d->commentaire)<div style="margin-top:4px; color:var(--text-2);">« {{ $d->commentaire }} »</div>@endif</td>
                    <td style="min-width:260px;">
                        @php [$fond, $texte] = $d->couleurs(); @endphp
                        <span style="font-size:11.5px; font-weight:700; padding:3px 8px; border-radius:20px; background:{{ $fond }}; color:{{ $texte }};">{{ $d->libelleStatut() }}</span>
                        {{-- Chaque étape, et son heure. --}}
                        <div style="margin-top:6px; font-size:11px; color:var(--text-3); line-height:1.6; border-left:2px solid var(--border); padding-left:8px;">
                            @foreach($d->etapes() as $etape)
                                <div><strong style="color:var(--text-2);">{{ $etape['libelle'] }}</strong> {{ $etape['le']?->format('d/m/Y H:i') }}</div>
                            @endforeach
                        </div>
                        @if($d->motif_echec)<div style="font-size:11.5px; color:#b91c1c; margin-top:4px;">Motif : {{ $d->motif_echec }}</div>@endif

                        @if($d->estOuverte())
                        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                            @if($d->statut === 'recue')
                            <form method="POST" action="{{ route('superadmin.stickers.mettre_a_jour', $d) }}" style="margin:0;">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="en_cours">
                                <button class="btn btn-sm" style="background:#fef9c3; color:#a16207; border:1px solid #fde68a;" title="Paiement reçu, achat lancé">En cours</button>
                            </form>
                            @endif
                            <button type="button" class="btn btn-sm" style="background:#dcfce7; color:#15803d; border:1px solid #86efac;"
                                    onclick="var f = document.getElementById('terminer-{{ $d->id }}'); f.style.display = f.style.display === 'none' ? 'flex' : 'none';">Terminé</button>
                            <form method="POST" action="{{ route('superadmin.stickers.mettre_a_jour', $d) }}" style="margin:0;"
                                  onsubmit="var m = prompt('Motif de l\'échec (visible par l\'entreprise) ?'); if (!m) return false; this.motif_echec.value = m; return true;">
                                @csrf @method('PUT')
                                <input type="hidden" name="action" value="echec">
                                <input type="hidden" name="motif_echec" value="">
                                <button class="btn btn-sm" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;">Échec</button>
                            </form>
                        </div>
                        <form method="POST" action="{{ route('superadmin.stickers.mettre_a_jour', $d) }}" id="terminer-{{ $d->id }}"
                              style="margin-top:8px; display:none; flex-direction:column; gap:6px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:8px;">
                            @csrf @method('PUT')
                            <input type="hidden" name="action" value="terminer">
                            <small style="color:#166534;">Relevé sur l'espace FNE du client (facultatif) :</small>
                            <input type="number" name="stock_communique" class="form-control" min="0" placeholder="Stock disponible (stickers)" style="font-size:12.5px; padding:6px;">
                            <input type="number" name="solde_communique" class="form-control" min="0" step="0.01" placeholder="Solde disponible (F)" style="font-size:12.5px; padding:6px;">
                            <input type="text" name="note_dcknowing" class="form-control" maxlength="1000" placeholder="Note visible par l'entreprise" style="font-size:12.5px; padding:6px;">
                            <button class="btn btn-sm" style="background:#16a34a; color:#fff; border:none; justify-content:center;">Confirmer : stickers achetés</button>
                        </form>
                        @endif
                        @if($d->traitee_le)
                            <div style="font-size:11px; color:var(--text-3); margin-top:6px;">Par {{ $d->traiteePar?->prenom }} le {{ $d->traitee_le->format('d/m/Y H:i') }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div style="padding:0 16px;">{{ $demandes->links() }}</div>
        @endif
    </div>
</div>
@endsection
