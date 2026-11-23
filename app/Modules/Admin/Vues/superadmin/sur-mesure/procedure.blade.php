@extends('admin::gabarits.application')
@section('titre', 'Mise en ligne d\'un espace sur mesure')
@section('topbar_titre', 'Superadmin · Sur mesure · Procédure')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-list-check"></i> Mise en ligne d'un espace sur mesure</h1>
        <p>La marche à suivre, étape par étape, pour un sous-domaine Selflow et pour un domaine propre à l'entreprise. Les deux ne se font pas de la même façon.</p>
    </div>
    <a href="{{ route('superadmin.sur_mesure.index') }}" class="btn btn-outline"><i class="fas fa-wand-magic-sparkles"></i> Espaces sur mesure</a>
</div>
@include('admin::superadmin.sur-mesure._procedure')
@endsection
