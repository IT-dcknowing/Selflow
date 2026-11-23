@extends('admin::gabarits.application')
@section('titre', 'Démos')
@section('topbar_titre', 'Superadmin · Demandes de démo')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-circle-play"></i> Demandes de démo</h1>
        <p>Les demandes laissées sur la page d'accueil, rangées par client. Déposez la démo une fois, puis transmettez-la : elle part par courriel et sur WhatsApp.</p>
    </div>
</div>

<div class="card" style="padding:18px; margin-bottom:20px;">
    <h3 style="margin:0 0 10px; font-size:16px;"><i class="fas fa-upload"></i> La démo à envoyer</h3>
    <form method="POST" action="{{ route('superadmin.demos.deposer') }}" enctype="multipart/form-data" style="display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); align-items:end;">
        @csrf
        <label style="font-size:13px; font-weight:700;">Fichier (vidéo MP4, WebM, MOV ou PDF)
            <input type="file" name="fichier" class="form-control" accept=".mp4,.webm,.mov,.pdf">
            <small style="font-weight:400; color:var(--text-3);">
                @if($fichier) Déposé : {{ basename($fichier) }}. Un nouveau fichier le remplace. @else Aucun fichier déposé. @endif
            </small>
        </label>
        <label style="font-size:13px; font-weight:700;">Ou un lien (YouTube, Drive…)
            <input type="url" name="lien" class="form-control" value="{{ $lien }}" placeholder="https://">
            <small style="font-weight:400; color:var(--text-3);">Le fichier passe avant le lien s'il y en a un.</small>
        </label>
        <label style="font-size:13px; font-weight:700; grid-column:1 / -1;">Message d'accompagnement
            <textarea name="message" class="form-control" rows="3" placeholder="Bonjour, voici la démonstration de Selflow…">{{ $texte }}</textarea>
        </label>
        <div><button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer la démo</button></div>
    </form>
</div>

@forelse($parClient as $email => $demandes)
    @php($derniere = $demandes->first())
    <div class="card" style="padding:16px; margin-bottom:12px;">
        <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:10px; align-items:center;">
            <div>
                <div style="font-weight:800;">{{ $email }}</div>
                <div style="font-size:13px; color:var(--text-3);">
                    WhatsApp {{ $derniere->whatsapp }} · {{ $derniere->libellesProfils() }} · {{ $demandes->count() }} demande(s), la dernière le {{ $derniere->created_at->format('d/m/Y H:i') }}
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                @if($derniere->statut === 'transmise')
                    <span style="font-size:12px; font-weight:700; color:#047857;"><i class="fas fa-check"></i> Transmise le {{ $derniere->transmise_le?->format('d/m/Y H:i') }}</span>
                @else
                    <span style="font-size:12px; font-weight:700; color:#b45309;"><i class="fas fa-clock"></i> À transmettre</span>
                @endif
                <form method="POST" action="{{ route('superadmin.demos.transmettre', $derniere) }}">
                    @csrf
                    <button class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i> {{ $derniere->statut === 'transmise' ? 'Retransmettre' : 'Transmettre la démo' }}</button>
                </form>
            </div>
        </div>
    </div>
@empty
    <div class="card" style="padding:24px; text-align:center; color:var(--text-3);">Aucune demande de démo pour l'instant.</div>
@endforelse

@if(session('ouvrir_whatsapp'))
<script>window.open(@json(session('ouvrir_whatsapp')), '_blank', 'noopener');</script>
@endif
@endsection
