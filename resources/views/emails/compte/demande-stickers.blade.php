@extends('emails.compte.gabarit')

@section('titre', $pourDcKnowing ? 'Nouvelle demande de stickers à acheter' : 'Votre demande de stickers est transmise')

@section('corps')
    @php
        $e = $demande->entreprise;
        $ligne = 'style="padding:7px 0;border-bottom:1px solid #eef2f7;font-size:13.5px;color:#334155;"';
    @endphp
    <p style="margin:0 0 16px;font-size:14px;color:#475569;line-height:1.7;">
        @if($pourDcKnowing)
            <strong>{{ $e?->nom }}</strong> demande à DC-Knowing d'acheter des stickers de certification pour son
            espace FNE.
        @else
            Votre demande est enregistrée et transmise à DC-Knowing, qui achète les stickers auprès de la DGI
            pour votre compte. Vous serez informé de chaque étape dans Selflow.
        @endif
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px;">
        <tr><td {!! $ligne !!}>Référence</td><td {!! $ligne !!} align="right"><strong>{{ strtoupper(substr($demande->uuid, 0, 8)) }}</strong></td></tr>
        <tr><td {!! $ligne !!}>Entreprise</td><td {!! $ligne !!} align="right"><strong>{{ $e?->nom }}</strong>@if($e?->ncc) — NCC {{ $e->ncc }}@endif</td></tr>
        <tr><td {!! $ligne !!}>Quantité</td><td {!! $ligne !!} align="right"><strong>{{ number_format($demande->quantite, 0, ',', ' ') }} stickers</strong></td></tr>
        <tr><td {!! $ligne !!}>Montant estimé</td><td {!! $ligne !!} align="right">{{ number_format((float) $demande->montant_estime, 0, ',', ' ') }} F CFA ({{ number_format((float) $demande->prix_unitaire, 0, ',', ' ') }} F l'unité)</td></tr>
        <tr><td {!! $ligne !!}>Point de vente</td><td {!! $ligne !!} align="right">{{ $demande->pointDeVente?->nom ?? 'Tous les sites' }}</td></tr>
        <tr><td {!! $ligne !!}>Urgence</td><td {!! $ligne !!} align="right">{{ \App\Modules\Admin\Modeles\DemandeSticker::URGENCES[$demande->urgence] ?? $demande->urgence }}</td></tr>
        <tr><td {!! $ligne !!}>Demandé par</td><td {!! $ligne !!} align="right">{{ trim(($demande->utilisateur?->prenom ?? '') . ' ' . ($demande->utilisateur?->nom ?? '')) }} — {{ $demande->utilisateur?->email }}</td></tr>
        @if($demande->contact_telephone)
            <tr><td {!! $ligne !!}>Téléphone</td><td {!! $ligne !!} align="right">{{ $demande->contact_telephone }}</td></tr>
        @endif
        <tr><td {!! $ligne !!}>Solde au moment de la demande</td><td {!! $ligne !!} align="right">{{ number_format((int) ($e?->fne_sticker_balance ?? 0), 0, ',', ' ') }}</td></tr>
    </table>
    @if($demande->commentaire)
        <p style="margin:0 0 18px;font-size:13px;color:#475569;line-height:1.6;background:#f8fafc;border-radius:8px;padding:12px 14px;">
            « {{ $demande->commentaire }} »
        </p>
    @endif
    @if($pourDcKnowing)
        @include('emails.compte.bouton', ['adresse' => route('superadmin.stickers.index'), 'libelle' => 'Traiter la demande'])
    @else
        @include('emails.compte.bouton', ['adresse' => route('admin.stickers.index'), 'libelle' => 'Suivre ma demande'])
    @endif
@endsection
