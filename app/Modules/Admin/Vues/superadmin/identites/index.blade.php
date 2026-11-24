@extends('admin::gabarits.application')
@section('titre', 'Identité des entreprises')
@section('topbar_titre', 'Superadmin · Identité des entreprises')

@section('contenu')
<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <h1 style="font-size:20px; margin:0;"><i class="fas fa-id-badge" style="color:var(--primary);"></i> Identité des entreprises</h1>
    <form method="GET" style="display:flex; gap:6px;">
        <input name="q" value="{{ $recherche }}" class="form-control" placeholder="Nom, NCC ou e-mail" style="width:240px;">
        <button class="btn btn-outline btn-sm"><i class="fas fa-magnifying-glass"></i></button>
    </form>
</div>
@if(session('succes'))<div class="alert alert-success" style="margin-bottom:12px;"><i class="fas fa-circle-check"></i> {{ session('succes') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" style="margin-bottom:12px;">{{ $errors->first() }}</div>@endif
<p style="font-size:12.5px; color:var(--text-3); margin:-4px 0 12px;">Le nom de l'entreprise, son e-mail de contact et l'adresse avec laquelle son responsable se connecte. Chaque modification est tracée.</p>

@foreach($entreprises as $e)
    @php $resp = $responsables->get($e->id); @endphp
    <form method="POST" action="{{ route('superadmin.identites.enregistrer', $e) }}" class="card"
          style="padding:12px 14px; margin-bottom:10px; display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px; align-items:end;">
        @csrf
        <label class="form-label" style="margin:0;">Nom de l'entreprise <span style="color:var(--text-3); font-weight:500;">{{ $e->ncc ? '· NCC ' . $e->ncc : '' }}</span>
            <input name="nom" value="{{ $e->nom }}" class="form-control" required maxlength="150"></label>
        <label class="form-label" style="margin:0;">E-mail de l'entreprise
            <input type="email" name="email" value="{{ $e->email }}" class="form-control"></label>
        <label class="form-label" style="margin:0;">Téléphone
            <input name="telephone" value="{{ $e->telephone }}" class="form-control"></label>
        <label class="form-label" style="margin:0;">Connexion du responsable{{ $resp ? ' (' . trim($resp->prenom . ' ' . $resp->nom) . ')' : '' }}
            @if($resp)<input type="email" name="responsable_email" value="{{ $resp->email }}" class="form-control" required>
            @else<div style="font-size:12px; color:var(--text-3); padding:9px 0;">Aucun responsable</div>@endif</label>
        <button class="btn btn-primary btn-sm" style="height:38px;"><i class="fas fa-floppy-disk"></i> Enregistrer</button>
    </form>
@endforeach
{{ $entreprises->links() }}
@endsection
