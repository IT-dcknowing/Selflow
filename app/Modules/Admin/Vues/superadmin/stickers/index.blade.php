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
            <thead><tr><th>Reçue le</th><th>Entreprise</th><th>Quantité</th><th>Site</th><th>Contact</th><th>Statut</th></tr></thead>
            <tbody>
            @foreach($demandes as $d)
                <tr>
                    <td style="font-size:12.5px; white-space:nowrap;">{{ $d->created_at->format('d/m/Y H:i') }}
                        @if($d->urgence === 'urgente')<div style="color:var(--danger); font-weight:800; font-size:11px;">URGENT</div>@endif</td>
                    <td><strong>{{ $d->entreprise?->nom }}</strong>
                        <div style="font-size:11.5px; color:var(--text-3);">NCC {{ $d->entreprise?->ncc ?? '—' }} · solde {{ number_format((int) ($d->entreprise?->fne_sticker_balance ?? 0), 0, ',', ' ') }}</div></td>
                    <td><strong>{{ number_format($d->quantite, 0, ',', ' ') }}</strong>
                        <div style="font-size:11.5px; color:var(--text-3);">≈ {{ number_format((float) $d->montant_estime, 0, ',', ' ') }} F</div></td>
                    <td style="font-size:12.5px;">{{ $d->pointDeVente?->nom ?? 'Tous' }}</td>
                    <td style="font-size:12px;">{{ trim(($d->utilisateur?->prenom ?? '') . ' ' . ($d->utilisateur?->nom ?? '')) }}<br>
                        <span style="color:var(--text-3);">{{ $d->utilisateur?->email }}@if($d->contact_telephone) · {{ $d->contact_telephone }}@endif</span>
                        @if($d->commentaire)<div style="margin-top:4px; color:var(--text-2);">« {{ $d->commentaire }} »</div>@endif</td>
                    <td style="min-width:240px;">
                        <form method="POST" action="{{ route('superadmin.stickers.mettre_a_jour', $d) }}" style="margin:0; display:flex; flex-direction:column; gap:6px;">
                            @csrf @method('PUT')
                            <select name="statut" class="form-control" style="font-size:12.5px; padding:6px;">
                                @foreach(Demande::STATUTS as $cle => $libelle)
                                    <option value="{{ $cle }}" @selected($d->statut === $cle)>{{ $libelle }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="note_dcknowing" class="form-control" maxlength="1000" placeholder="Note visible par l'entreprise" value="{{ $d->note_dcknowing }}" style="font-size:12.5px; padding:6px;">
                            <button class="btn btn-primary btn-sm" style="justify-content:center;">Enregistrer</button>
                            @if($d->traitee_le)
                                <span style="font-size:11px; color:var(--text-3);">Par {{ $d->traiteePar?->prenom }} le {{ $d->traitee_le->format('d/m/Y H:i') }}</span>
                            @endif
                        </form>
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
