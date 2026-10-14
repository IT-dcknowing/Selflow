@extends('admin::gabarits.application')
@section('titre', 'Demandes B2B reçues')
@section('topbar_titre', 'B2B — Demandes reçues')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-handshake" style="color:var(--primary); margin-right:8px;"></i> Demandes B2B reçues</h1>
        <p>Les demandes de prix et les commandes que vous adressent vos clients inscrits sur Selflow.
           Répondez, confirmez selon votre stock, puis livrez et facturez — tout se fait depuis la page de chaque demande.</p>
    </div>
</div>

@include('admin::b2b.partials.liste')
@endsection
