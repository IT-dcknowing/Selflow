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
    <form method="POST" action="{{ route('superadmin.demos.deposer') }}" enctype="multipart/form-data">
        @csrf
        <div style="display:grid; gap:16px; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); align-items:start;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="demo-fichier">Fichier : vidéo MP4, WebM, MOV ou PDF</label>
                {{-- Le champ de fichier natif est masqué : le bouton et le nom du fichier ont la même hauteur que le champ voisin. --}}
                <label for="demo-fichier" style="display:flex; align-items:center; gap:10px; height:42px; padding:0 12px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer;">
                    <span class="btn btn-outline btn-sm" style="pointer-events:none;"><i class="fas fa-upload"></i> Choisir un fichier</span>
                    <span id="demo-fichier-nom" style="font-size:13px; color:var(--text-3); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $fichier ? basename($fichier) : 'Aucun fichier choisi' }}</span>
                </label>
                <input type="file" id="demo-fichier" name="fichier" accept=".mp4,.webm,.mov,.pdf" style="position:absolute; width:1px; height:1px; opacity:0;"
                       onchange="document.getElementById('demo-fichier-nom').textContent = this.files[0] ? this.files[0].name : 'Aucun fichier choisi'">
                <small style="display:block; margin-top:6px; color:var(--text-3);">@if($fichier) Déposé : {{ basename($fichier) }}. Un nouveau fichier le remplace. @else Aucun fichier déposé. @endif</small>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="demo-lien">Ou un lien : YouTube, Drive…</label>
                <input type="url" id="demo-lien" name="lien" class="form-control" style="height:42px;" value="{{ $lien }}" placeholder="https://">
                <small style="display:block; margin-top:6px; color:var(--text-3);">Le fichier passe avant le lien s'il y en a un.</small>
            </div>
            <div class="form-group" style="margin:0; grid-column:1 / -1;">
                <label class="form-label" for="demo-message">Message d'accompagnement</label>
                <textarea id="demo-message" name="message" class="form-control" rows="3" placeholder="Bonjour, voici la démonstration de Selflow…">{{ $texte }}</textarea>
            </div>
        </div>
        <div style="margin-top:14px;"><button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer la démo</button></div>
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
