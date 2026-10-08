@extends('admin::gabarits.application')
@section('titre', 'Recettes — Fiches Techniques')
@section('topbar_titre', 'Production — Fiches Techniques')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-flask" style="color:var(--primary); margin-right:8px;"></i> Recettes & Fiches Techniques</h1>
        <p>Gérez les formules de fabrication et la composition de vos produits finis.</p>
    </div>
    <a href="{{ route('admin.production.fiches_techniques.creer') }}" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle Recette
    </a>
</div>

{{-- Zone d'alertes --}}
@if(session('succes'))
    <div class="alert alert-success" style="margin-bottom:20px;">
        <i class="fas fa-check-circle"></i> {{ session('succes') }}
    </div>
@endif
@if(session('erreur'))
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <i class="fas fa-exclamation-circle"></i> {{ session('erreur') }}
    </div>
@endif

<div class="card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('admin.production.fiches_techniques.index') }}" id="formRechercheFiches" style="display:flex; gap:12px; align-items:center;">
            <div style="flex:1; position:relative;">
                <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:13px;"></i>
                <input type="text" name="recherche" value="{{ request('recherche') }}" placeholder="Rechercher par nom ou référence de produit..." class="form-control" style="padding-left:36px; height:40px;"
                       oninput="clearTimeout(window._rechercheFichesTimer); window._rechercheFichesTimer = setTimeout(() => document.getElementById('formRechercheFiches').submit(), 500);">
            </div>
            <button type="submit" class="btn btn-outline" style="height:40px;">
                <i class="fas fa-filter"></i> Filtrer
            </button>
            @if(request('recherche'))
                <a href="{{ route('admin.production.fiches_techniques.index') }}" class="btn btn-outline" style="height:40px; color:var(--danger); border-color:var(--danger);">
                    Effacer
                </a>
            @endif
        </form>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        @if($fiches->isEmpty())
            <div style="padding:60px; text-align:center; color:var(--text-3);">
                <i class="fas fa-receipt" style="font-size:48px; display:block; margin-bottom:16px; opacity:.3;"></i>
                Aucune fiche technique / recette configurée.<br>
                Cliquez sur <strong>Nouvelle Recette</strong> pour lier des matières premières à vos produits finis.
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width: 15%;">Réf. Fini</th>
                        <th style="width: 30%;">Produit Fini</th>
                        <th style="width: 15%; text-align: center;">Ingrédients</th>
                        <th style="width: 25%;">Description / Note</th>
                        <th style="width: 15%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($fiches as $fiche)
                        <tr>
                            <td style="font-weight:700; color:var(--primary);">{{ $fiche->produitFini->reference }}</td>
                            <td style="font-weight:600;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    @if($fiche->produitFini->photo)
                                        <img src="{{ $fiche->produitFini->photo_url }}" style="width:28px; height:28px; border-radius:4px; object-fit:cover;">
                                    @else
                                        <div style="width:28px; height:28px; border-radius:4px; background:var(--bg3); display:flex; align-items:center; justify-content:center; color:var(--text-3); font-size:11px;">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    @endif
                                    {{ $fiche->produitFini->nom }}
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-purple" style="font-size: 11.5px; padding: 4px 10px;">
                                    {{ $fiche->details->count() }} ingrédient(s)
                                </span>
                            </td>
                            <td style="color:var(--text-2); font-size:12.5px;">{{ Str::limit($fiche->description, 60) ?: '—' }}</td>
                            <td style="text-align: right;">
                                <div style="display:inline-flex; gap:6px;">
                                    <a href="{{ route('admin.production.fiches_techniques.modifier', $fiche) }}" class="btn btn-outline btn-sm" style="padding:5px 9px;" title="Modifier la recette">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="{{ route('admin.production.fiches_techniques.supprimer', $fiche) }}" onsubmit="return confirm('Voulez-vous vraiment supprimer cette fiche technique ?')" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline btn-sm" style="padding:5px 9px; color:var(--danger); border-color:var(--danger);" title="Supprimer la recette">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="padding: 10px 16px;">
                {{ $fiches->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const champ = document.querySelector('#formRechercheFiches input[name="recherche"]');
        if (champ && champ.value) {
            champ.focus();
            champ.setSelectionRange(champ.value.length, champ.value.length);
        }
    });
</script>
@endsection
