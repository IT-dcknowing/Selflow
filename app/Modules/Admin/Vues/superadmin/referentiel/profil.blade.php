@extends('admin::gabarits.application')

@section('titre', 'Référentiel · ' . $profil->nom)
@section('topbar_titre', 'Référentiel · ' . $profil->nom)

@section('styles')
<style>
    .ref-tete { background:#fff; border:1px solid var(--border); border-radius:12px;
                padding:18px 22px; margin-bottom:22px; }
    .ref-tete h2 { font-size:19px; font-weight:800; margin:0 0 6px; }
    .ref-tete .meta { font-size:12.5px; color:var(--text-3); }
    .ref-tete .desc { font-size:13.5px; color:var(--text-2); margin-top:10px; line-height:1.55; }
    .ref-tete .note { font-size:13px; color:var(--text-2); margin-top:12px; padding:10px 14px;
                      background:#fffbeb; border-left:3px solid #d97706; border-radius:6px; }

    .ref-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border);
                 border-radius:12px; overflow:hidden; }
    .ref-table th { text-align:left; padding:10px 14px; font-size:10.5px; text-transform:uppercase;
                    letter-spacing:.5px; color:var(--text-3); background:#f8fafc;
                    border-bottom:1px solid var(--border); white-space:nowrap; }
    .ref-table td { padding:10px 14px; border-bottom:1px solid var(--border); font-size:13px; }
    .ref-table tr:last-child td { border-bottom:none; }
    .cadre-defilant { overflow-x:auto; margin-bottom:26px; }

    .mod { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase;
           padding:3px 7px; border-radius:4px; margin-right:4px; background:rgba(0,43,92,.06); color:var(--primary); }
    .cpt { font-family:ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size:12px; color:var(--primary); }
    .vide { color:var(--text-3); }
    .section-titre { font-size:13px; font-weight:700; color:var(--text-1); text-transform:uppercase;
                     letter-spacing:.5px; margin:26px 0 12px; display:flex; align-items:center; gap:8px; }
</style>
@endsection

@section('contenu')

<a href="{{ route('superadmin.referentiel.index') }}" class="btn btn-outline btn-sm" style="margin-bottom:16px;">
    <i class="fas fa-arrow-left"></i> Tous les profils
</a>

<div class="ref-tete">
    <h2>{{ $profil->nom }}</h2>
    <div class="meta">
        {{ $profil->categorie->nom }} &nbsp;·&nbsp; <code>{{ $profil->code }}</code>
        &nbsp;·&nbsp; {{ $familles->count() }} familles &nbsp;·&nbsp; {{ $articles->count() }} articles
    </div>

    @if($profil->description)
        <div class="desc">{{ $profil->description }}</div>
    @endif

    <div style="margin-top:12px;">
        @forelse($profil->modulesOuverts() as $module)
            <span class="mod">{{ $module }}</span>
        @empty
            <span class="vide" style="font-size:12.5px;">Aucun module — ni stock, ni production.</span>
        @endforelse
    </div>

    @if($profil->note_gestion)
        <div class="note"><strong>Note de gestion :</strong> {{ $profil->note_gestion }}</div>
    @endif
</div>

<div class="section-titre"><i class="fas fa-layer-group"></i> Familles et leurs comptes</div>
<div class="cadre-defilant">
    <table class="ref-table">
        <thead>
            <tr><th>Code</th><th>Famille</th><th>Type</th><th>Vente</th><th>Achat</th><th>Stock</th><th>Variation</th></tr>
        </thead>
        <tbody>
            @foreach($familles as $famille)
            <tr>
                <td><strong>{{ $famille->code }}</strong></td>
                <td>
                    {{ $famille->nom }}
                    @if($famille->intituleCompte('compte_vente'))
                        <div class="vide" style="font-size:11.5px;">{{ $famille->intituleCompte('compte_vente') }}</div>
                    @endif
                </td>
                <td>{{ $famille->typeArticle->code }}</td>
                @foreach(['compte_vente', 'compte_achat', 'compte_stock', 'compte_variation'] as $champ)
                    <td>
                        @if($famille->$champ)
                            <span class="cpt">{{ $famille->$champ }}</span>
                        @else
                            <span class="vide">—</span>
                        @endif
                    </td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="section-titre"><i class="fas fa-box"></i> Articles types</div>
<p style="font-size:13px; color:var(--text-2); margin-bottom:12px;">
    Le classeur laisse volontairement les prix et le stock initial vides : ils varient
    selon la zone et la période. L'utilisateur les saisit à la souscription.
</p>
<div class="cadre-defilant">
    <table class="ref-table">
        <thead><tr><th>Code</th><th>Désignation</th><th>Famille</th><th>Unité</th><th>Vente</th><th>Achat</th></tr></thead>
        <tbody>
            @foreach($articles as $article)
            <tr>
                <td><strong>{{ $article->code }}</strong></td>
                <td>{{ $article->designation }}</td>
                <td>{{ $article->famille->nom }}</td>
                <td>{{ $article->unite ?? '—' }}</td>
                <td>
                    @if($article->compte_vente)<span class="cpt">{{ $article->compte_vente }}</span>@else<span class="vide">—</span>@endif
                </td>
                <td>
                    @if($article->compte_achat)<span class="cpt">{{ $article->compte_achat }}</span>@else<span class="vide">—</span>@endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

@endsection
