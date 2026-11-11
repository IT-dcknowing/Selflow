@extends('admin::gabarits.application')

@section('titre', 'Configurer mon entreprise')
@section('topbar_titre', 'Configurer mon entreprise')

@section('styles')
<style>
    .sous-fil { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:24px; }
    .sous-fil .pas { display:flex; align-items:center; gap:8px; padding:8px 14px; border-radius:8px;
                     font-size:12.5px; font-weight:600; background:#f1f5f9; color:var(--text-3); }
    .sous-fil .pas.faite { background:rgba(5,150,105,.08); color:#059669; }
    .sous-fil .pas.ici   { background:var(--primary); color:#fff; }
    .sous-fil .pas .num  { width:20px; height:20px; border-radius:50%; background:rgba(0,0,0,.08);
                           display:inline-flex; align-items:center; justify-content:center; font-size:11px; }
    .sous-fil .pas.ici .num { background:rgba(255,255,255,.2); }

    .sous-tete { margin-bottom:20px; }
    .sous-tete h2 { font-size:21px; font-weight:800; margin:0 0 6px; }
    .sous-tete p  { font-size:14px; color:var(--text-2); margin:0; max-width:58ch; line-height:1.55; }

    .tuiles { display:grid; grid-template-columns:repeat(auto-fill, minmax(210px,1fr)); gap:14px; margin-bottom:24px; }

    /* Le référentiel manquant : le dire, plutôt que laisser la page muette. */
    .referentiel-absent {
        display:flex; gap:14px; align-items:flex-start;
        padding:18px 20px; margin-bottom:24px;
        background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b;
        border-radius:10px; color:#78350f;
    }
    .referentiel-absent i { font-size:18px; color:#b45309; margin-top:2px; }
    .referentiel-absent strong { display:block; margin-bottom:6px; font-size:14px; }
    .referentiel-absent p { margin:0; font-size:13px; line-height:1.6; }
    .referentiel-absent code {
        font-family:ui-monospace, Menlo, monospace; font-size:12.5px;
        background:#fff; border:1px solid #fde68a; border-radius:5px; padding:2px 6px;
    }
    .tuile { position:relative; background:#fff; border:2px solid var(--border); border-radius:12px;
             padding:18px; cursor:pointer; transition:border-color .12s, box-shadow .12s; }
    .tuile:hover { border-color:#cbd5e1; }
    .tuile input { position:absolute; opacity:0; pointer-events:none; }
    .tuile input:checked ~ .corps { color:var(--primary); }
    .tuile:has(input:checked) { border-color:var(--primary); box-shadow:0 0 0 3px rgba(0,43,92,.06); }
    .tuile .titre { font-weight:700; font-size:14.5px; margin-bottom:4px; }
    .tuile .detail { font-size:12px; color:var(--text-3); line-height:1.5; }

    .liste { background:#fff; border:1px solid var(--border); border-radius:12px; overflow:hidden; margin-bottom:22px; }
    .ligne { display:flex; align-items:flex-start; gap:12px; padding:13px 18px; border-bottom:1px solid var(--border); }
    .ligne:last-child { border-bottom:none; }
    .ligne:hover { background:#fafbfc; }
    .ligne input[type=checkbox] { margin-top:3px; width:16px; height:16px; flex-shrink:0; }
    .ligne .nom { font-weight:600; font-size:14px; }
    .ligne .sous { font-size:12px; color:var(--text-3); margin-top:2px; }
    .ligne .droite { margin-left:auto; font-size:12px; color:var(--text-3); white-space:nowrap; }

    /* Ce qui est acquis : un métier souscrit, un module qui porte des données.
       La case reste cochée et désactivée ; sans un fond et un mot, l'utilisateur
       la croit simplement grisée par erreur et cherche à la décocher. */
    .ligne.acquise { background:rgba(5,150,105,.045); }
    .ligne.acquise:hover { background:rgba(5,150,105,.075); }
    .ligne.acquise .nom { color:#065f46; }
    .badge-acquis {
        display:inline-block; padding:3px 9px; border-radius:20px;
        background:rgba(5,150,105,.12); color:#047857;
        font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
    }
    .note-verrou {
        display:flex; gap:10px; align-items:flex-start;
        background:rgba(5,150,105,.06); border:1px solid rgba(5,150,105,.22);
        border-radius:10px; padding:13px 16px; margin-bottom:16px;
        font-size:12.5px; line-height:1.6; color:#065f46;
    }
    .note-verrou i { margin-top:2px; color:#059669; }

    .autre { background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:16px 18px; margin-bottom:22px; }
    .autre label { font-weight:700; font-size:13px; color:#92400e; display:block; margin-bottom:6px; }
    .autre p { font-size:12.5px; color:#b45309; margin:0 0 10px; line-height:1.5; }

    .tableau-prix { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border);
                    border-radius:12px; overflow:hidden; }
    .tableau-prix th { text-align:left; padding:10px 14px; font-size:10.5px; text-transform:uppercase;
                       letter-spacing:.5px; color:var(--text-3); background:#f8fafc; border-bottom:1px solid var(--border); }
    .tableau-prix td { padding:8px 14px; border-bottom:1px solid var(--border); font-size:13px; }
    .tableau-prix tr:last-child td { border-bottom:none; }
    .tableau-prix input { width:100%; padding:6px 9px; border:1px solid var(--border); border-radius:6px; font-size:13px; }
    .tableau-prix input[type=number] { text-align:right; font-variant-numeric:tabular-nums; }
    .cadre-defilant { overflow-x:auto; margin-bottom:22px; }

    .pieds { display:flex; gap:10px; align-items:center; padding-top:6px; }
    .cpt { font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace; font-size:11.5px; color:var(--primary); }
    .vide { color:var(--text-3); }
</style>
@endsection

@section('contenu')
@php $comptaOuverte = (bool) $entreprise->comptabiliteOuverte(); @endphp

{{-- ── Le fil du parcours ── --}}
@php
    $titres = [1 => 'Domaine', 2 => 'Secteur précis', 3 => 'Modules', 4 => 'Catégories', 5 => 'Prix'];
@endphp
<div class="sous-fil">
    @foreach($titres as $numero => $titre)
        @php
            $classe = $numero === $etape ? 'ici' : ($numero <= $entreprise->souscription_etape ? 'faite' : '');
        @endphp
        @if($numero <= $entreprise->souscription_etape + 1 && $numero !== $etape)
            <a href="{{ $routeEtape($numero) }}"
               class="pas {{ $classe }}" style="text-decoration:none;">
                <span class="num">{{ $numero }}</span> {{ $titre }}
            </a>
        @else
            <span class="pas {{ $classe }}"><span class="num">{{ $numero }}</span> {{ $titre }}</span>
        @endif
    @endforeach
</div>

@if(session('succes'))
    <div class="alert alert-success" style="margin-bottom:18px;">{{ session('succes') }}</div>
@endif
@if(session('erreur') || $errors->any())
    <div class="alert alert-danger" style="margin-bottom:18px;">
        {{ session('erreur') ?? $errors->first() }}
    </div>
@endif

<form method="POST" action="{{ $routeEnregistrer }}" id="form-souscription">
    @csrf

    {{-- ══ 1. Le domaine ══ --}}
    @if($etape === 1)
        <div class="sous-tete">
            <h2>Dans quel domaine travaillez-vous ?</h2>
            <p>Ce choix ne fait que restreindre la liste suivante. Vous pouvez revenir ici
               autant de fois que vous voulez, y compris une fois la configuration terminée.</p>
        </div>

        {{-- Le parcours se reprend surtout pour ajouter. Sans cette phrase, un
             utilisateur qui revient croit qu'en choisissant un autre domaine il
             remplace le sien, et n'ose pas. --}}
        @if(!empty($domainesDejaLa))
            <p class="note-verrou">
                <i class="fas fa-circle-plus"></i>
                Vous travaillez déjà en <strong>{{ implode(', ', $domainesDejaLa) }}</strong>.
                En choisir un autre <strong>n'enlève rien</strong> : le parcours ajoute les métiers,
                les rayons et les comptes du nouveau domaine à ceux que vous avez déjà.
            </p>
        @endif

        @if($categories->isNotEmpty() || !empty($entreprise->domaines_personnalises))
        <div class="tuiles">
            @foreach($categories as $categorie)
            <label class="tuile">
                <input type="radio" name="categorie_id" data-etape-suivante value="{{ $categorie->id }}"
                       {{ ($choix['categorie_id'] ?? null) == $categorie->id ? 'checked' : '' }}>
                <div class="corps">
                    <div class="titre">{{ $categorie->nom }}</div>
                    <div class="detail">{{ $categorie->profils_count }} secteur(s) précis</div>
                </div>
            </label>
            @endforeach
            @foreach((array) $entreprise->domaines_personnalises as $domaine)
                <label class="tuile">
                    <input type="radio" name="domaine_personnalise" data-etape-suivante value="{{ $domaine }}"
                           {{ ($choix['domaine_personnalise'] ?? '') === $domaine ? 'checked' : '' }}>
                    <div class="corps">
                        <div class="titre">{{ $domaine }}</div>
                        <div class="detail"><span class="badge-acquis">Créé</span></div>
                    </div>
                </label>
            @endforeach
        </div>
        @else
            <p class="note-verrou">Aucun domaine du référentiel n'est disponible. Vous pouvez créer le vôtre ci-dessous.</p>
        @endif
        <div class="autre" style="background:#f0fdf4;border-color:#86efac;">
            <label for="domaine_personnalise" style="color:#166534;">Créer un domaine</label>
            <input type="text" id="domaine_personnalise" name="nouveau_domaine" class="form-control"
                   value="{{ old('nouveau_domaine') }}" list="domaines-personnalises-existants" maxlength="120" placeholder="Ex. : Services aux entreprises">
            <datalist id="domaines-personnalises-existants">@foreach((array) $entreprise->domaines_personnalises as $domaine)<option value="{{ $domaine }}">@endforeach</datalist>
            <button type="submit" class="btn btn-outline" style="margin-top:8px;"><i class="fas fa-plus"></i> Créer</button>
        </div>

    {{-- ══ 2. Le métier ══ --}}
    @elseif($etape === 2)
        <div class="sous-tete">
            <h2>Quel est votre secteur précis ?</h2>
            <p>Sélectionnez un ou plusieurs secteurs précis. Vous pouvez aussi créer vos propres secteurs : ils auront les mêmes cases à cocher et resteront disponibles dans ce domaine.</p>
        </div>

        @if(!empty($profilsAcquis))
            <p class="note-verrou">
                <i class="fas fa-circle-check"></i>
                Les secteurs précis marqués <strong>déjà en place</strong> vous appartiennent : leurs catégories,
                leurs articles et leurs comptes sont chez vous. Ils ne se retirent pas d'ici — vous
                pouvez en ajouter d'autres, ici ou dans un autre domaine.
            </p>
        @endif

        <div class="liste">
            @foreach($profils as $profil)
            @php $acquis = in_array($profil->code, $profilsAcquis ?? [], true); @endphp
            <label class="ligne {{ $acquis ? 'acquise' : '' }}">
                <input type="checkbox" name="profils[]" value="{{ $profil->code }}"
                       {{ $acquis || in_array($profil->code, $choix['profils'] ?? [], true) ? 'checked' : '' }}
                       {{ $acquis ? 'disabled' : '' }}>
                <div>
                    <div class="nom">{{ $profil->nom }}</div>
                    @if($profil->description)
                        <div class="sous">{{ $profil->description }}</div>
                    @endif
                </div>
                <div class="droite">
                    @if($acquis)
                        <span class="badge-acquis">déjà en place</span>
                    @else
                        {{ $profil->familles_count }} catégories · {{ $profil->articles_count }} articles
                    @endif
                </div>
            </label>
            {{-- Une case désactivée n'est pas transmise. Le contrôleur remet de
                 toute façon les métiers acquis dans le choix retenu ; ce relais
                 évite que l'écran et l'enregistrement racontent deux histoires. --}}
            @if($acquis)
                <input type="hidden" name="profils[]" value="{{ $profil->code }}">
            @endif
            @endforeach
            @if(!empty($secteursPersonnalises))
                @foreach($secteursPersonnalises as $secteur)
                    <label class="ligne">
                        <input type="checkbox" name="secteurs_personnalises_selection[]" value="{{ $secteur }}"
                               {{ in_array($secteur, $choix['secteurs_personnalises_selection'] ?? [], true) ? 'checked' : '' }}>
                        <div style="flex:1;">
                            <div class="nom">{{ $secteur }}</div>
                            <div class="sous">Secteur personnalisé · disponible uniquement dans ce domaine</div>
                        </div>
                        <div class="droite"><span class="badge-acquis">Créé</span></div>
                    </label>
                @endforeach
            @endif
        </div>
        <div class="autre">
            <label>Créer un secteur précis</label>
            <div id="secteurs-personnalises">
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                    <input type="text" name="secteurs_personnalises[]" class="form-control" maxlength="150" placeholder="Nom du secteur précis" data-nouveau-secteur>
                    <button type="button" class="btn btn-outline-danger" data-retirer-saisie title="Retirer"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="btn btn-outline" id="ajouter-secteur"><i class="fas fa-plus"></i> Ajouter un autre secteur</button>
                <button type="submit" name="action_secteur" value="creer" class="btn btn-primary"><i class="fas fa-check"></i> Créer les secteurs</button>
            </div>
        </div>

    {{-- ══ 3. Les modules ══ --}}
    @elseif($etape === 3)
        <div class="sous-tete">
            <h2>Ce que votre métier demande</h2>
            <p>Voici les modules correspondant à votre activité. Ils sont affichés à titre informatif et sont gérés automatiquement.</p>
        </div>

        @php
            // Ce qu'on ne décoche pas, et pourquoi. `points_de_vente` ne porte
            // pas que les sites : le personnel et les habilitations vivent
            // derrière lui. Le retirer priverait l'administrateur de l'écran
            // où il gère ses propres utilisateurs et leurs droits.
            $structurels = \App\Modules\Admin\Modeles\Entreprise::MODULES_STRUCTURELS;
            $raisons = [
                'principal'       => "Le socle de l'application — il reste toujours ouvert.",
                'points_de_vente' => 'Vos sites, votre personnel et leurs droits — il reste toujours ouvert.',
            ];

            // Un module déjà ouvert n'est pas un choix à refaire (chantier
            // 3.5) : l'étape le présentait coché, « à activer », alors qu'il
            // l'était déjà. Il se montre désormais comme acquis.
            $dejaOuverts = (array) ($entreprise->modules_actifs ?? []);
            $tousOuverts = $modulesProposes !== []
                && array_diff($modulesProposes, $dejaOuverts) === [];
        @endphp

        @if($tousOuverts)
            <p class="note-verrou">
                <i class="fas fa-circle-check"></i>
                Tous les modules que votre métier demande sont <strong>déjà ouverts</strong> :
                il n'y a rien à choisir ici. Passez à l'étape suivante.
            </p>
        @endif

        @if(!empty($modulesVerrouilles))
            <p class="note-verrou">
                <i class="fas fa-lock"></i>
                Certains modules portent déjà vos données. Les refermer ne supprimerait rien,
                mais ferait disparaître de votre menu les écrans où ces données se lisent :
                <strong>ils restent ouverts</strong>.
            </p>
        @endif

        <div class="liste">
            @foreach($modulesProposes as $module)
                <div class="ligne">
                    <div>
                        <div class="nom">{{ \App\Modules\Admin\Modeles\Entreprise::libelleModule($module) }}</div>
                    </div>
                    @if(in_array($module, $dejaOuverts, true))
                        <div class="droite"><span class="badge-acquis">déjà ouvert</span></div>
                    @endif
                </div>
            @endforeach
        </div>
    {{-- ══ 4. Les catégories ══ --}}
    @elseif($etape === 4)
        <div class="sous-tete">
            <h2>Les catégories de votre activité</h2>
            <p>Seules les catégories du domaine et du secteur sélectionnés sont affichées. Chaque catégorie créée reste dans ce secteur et ne sera pas visible dans les autres.</p>
        </div>
        <div class="tuiles" id="liste-categories-souscription">
            @foreach($familles as $famille)
                <label class="tuile">
                    <input type="checkbox" name="familles[]" value="{{ $famille->code }}"
                           {{ in_array($famille->code, $choix['familles'] ?? []) ? 'checked' : '' }}>
                    <div class="corps">
                        <div class="titre">{{ $famille->nom }}</div>
                        <div class="detail">{{ $famille->profil->nom }} · {{ $famille->articles_count }} produit(s)</div>
                    </div>
                </label>
            @endforeach
            @foreach($categoriesPersonnalisees ?? [] as $categoriePerso)
                <div class="tuile" style="cursor:default;">
                    <label style="display:block;cursor:pointer;margin:0;">
                        <input type="checkbox" name="categories_personnalisees_selection[]" value="{{ $categoriePerso->id }}" checked>
                        <div class="corps">
                            <div class="titre">{{ $categoriePerso->nom }}</div>
                            <div class="detail"><span class="badge-acquis">Créé</span></div>
                        </div>
                    </label>
                    <button type="button" class="btn btn-outline-danger" data-supprimer-categorie="{{ $categoriePerso->id }}"
                            title="Supprimer cette catégorie" style="position:absolute;right:12px;top:12px;z-index:2;">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            @endforeach
        </div>
        <div class="autre" style="background:#f0fdf4;border-color:#86efac;">
            <label style="color:#166534;">Créer une catégorie dans un secteur sélectionné</label>
            <p style="color:#166534;margin:0 0 12px;">Chaque secteur possède sa propre liste. Saisissez la catégorie directement sous le secteur concerné ; aucun rattachement supplémentaire n'est nécessaire.</p>
            @forelse($metiersCourants ?? [] as $indexMetier => $metier)
                <div class="bloc-categories-secteur" style="background:#fff;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin-bottom:10px;">
                    <div style="font-weight:700;color:#166534;margin-bottom:8px;">{{ $metier }}</div>
                    <div class="nouvelles-categories-secteur" data-metier="{{ $metier }}" data-index="{{ $indexMetier }}">
                        <div class="ligne-saisie" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                            <input type="text" name="categories_personnalisees[{{ $indexMetier }}][nom]" class="form-control" maxlength="120" placeholder="Nom de la catégorie">
                            <input type="hidden" name="categories_personnalisees[{{ $indexMetier }}][metier_configuration]" value="{{ $metier }}">
                            <button type="button" class="btn btn-outline-danger" data-retirer-saisie title="Retirer"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline" data-ajouter-categorie="{{ $metier }}"><i class="fas fa-plus"></i> Ajouter une catégorie à ce secteur</button>
                </div>
            @empty
                <p style="color:#92400e;margin:0;">Sélectionnez au moins un secteur à l'étape précédente pour créer ses catégories.</p>
            @endforelse
        </div>

{{-- ══ 5. Prix et stock ══ --}}
    @elseif($etape === 5)
        @php
            // La colonne de stock n'a de sens que si le module est ouvert, qu'un
            // site existe pour l'accueillir, et qu'au moins un article se compte :
            // pour un cabinet comptable, dont tous les articles sont des
            // missions, elle serait une colonne de tirets.
            $avecStock = $siteDuStock && $articles->contains(fn ($a) => $a->estStockable());
            $colonnes  = $avecStock ? 7 : 6;
        @endphp

        <div class="sous-tete">
            <h2>Vos prix{{ $avecStock ? ' et votre stock de départ' : '' }}</h2>
            <p>Le catalogue livré ne porte aucun prix : ils varient selon la zone et la
               période. Choisissez la catégorie de chaque article dans la liste, puis saisissez
               vos montants. Une catégorie manquante se crée à l'étape précédente.
               @if($avecStock)
                   La dernière colonne reçoit les quantités comptées aujourd'hui
                   à « {{ $siteDuStock->nom }} » : c'est votre inventaire d'ouverture.
               @endif
            </p>
        </div>

        @if($categoriesEntreprise->isEmpty())
            <div class="alert alert-warning" style="margin-bottom:16px;">
                Aucune catégorie n'est encore disponible dans votre entreprise.
                <a href="{{ $routeEtape(4) }}" style="font-weight:700;text-decoration:underline;">Retourner à l'étape 4 pour créer une catégorie</a>.
            </div>
        @endif

        <div class="cadre-defilant">
            <table class="tableau-prix">
                <thead>
                    <tr><th>Référence</th><th>Article</th><th>Catégorie</th><th>Point de vente</th>
                        <th style="width:130px;">Prix d'achat</th><th style="width:130px;">Prix de vente</th>
                        @if($avecStock)<th style="width:130px;">Stock de départ</th>@endif
                        <th style="width:48px;"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($articles as $i => $article)
                    <tr>
                        <td><span class="cpt" data-reference>{{ $article->reference }}</span></td>
                        <td>
                            <input type="hidden" name="articles[{{ $i }}][id]" value="{{ $article->id }}">
                            <input type="text" name="articles[{{ $i }}][nom]" value="{{ $article->nom }}" maxlength="255">
                        </td>
                        <td>
                            <select required name="articles[{{ $i }}][categorie_id]" class="form-control">
                                <option value="">Choisir une catégorie</option>
                                @foreach($categoriesEntreprise as $categorie)
                                    <option value="{{ $categorie->id }}" {{ (int) $article->categorie_id === (int) $categorie->id ? 'selected' : '' }}>{{ $categorie->nom }}{{ $categorie->domaine_configuration ? ' — ' . $categorie->domaine_configuration : '' }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td><select name="articles[{{ $i }}][point_de_vente_id]" class="form-control"><option value="">Tous</option>@foreach($pointsDeVente as $site)<option value="{{ $site->id }}" {{ $article->stocks->contains('point_de_vente_id', $site->id) ? 'selected' : '' }}>{{ $site->nom }}</option>@endforeach</select></td>
                        <td><input type="number" name="articles[{{ $i }}][prix_achat]" min="0" step="1"
                                   value="{{ $article->prix_achat > 0 ? (int) $article->prix_achat : '' }}"></td>
                        <td><input type="number" name="articles[{{ $i }}][prix_vente]" min="0" step="1"
                                   value="{{ $article->prix_vente > 0 ? (int) $article->prix_vente : '' }}"></td>
                        @if($avecStock)
                        <td>
                            @if($article->estStockable())
                                <input type="number" name="articles[{{ $i }}][stock_initial]" min="0" step="1"
                                       placeholder="0" value="{{ $article->stockSur($siteDuStock->id) ?: '' }}">
                            @else
                                {{-- Une prestation ne s'épuise pas : rien à compter. --}}
                                <span class="vide" title="Cet article ne se stocke pas">—</span>
                            @endif
                        </td>
                        @endif
                        <td><button type="button" class="btn btn-outline-danger" data-retirer-article title="Retirer cette ligne"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    @empty
                    <tr data-catalogue-vide><td colspan="{{ $colonnes + 1 }}" style="text-align:center; padding:26px; color:var(--text-3);">
                        Votre catalogue est vide — ajoutez votre premier article avec le bouton ci-dessous.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-outline" id="ajouter-article" {{ $categoriesEntreprise->isEmpty() ? 'disabled' : '' }}><i class="fas fa-plus"></i> Ajouter un article</button>
    @endif

    <div class="pieds">
        {{-- Un bouton qui ne peut mener nulle part vaut mieux caché : sans
             domaine à choisir, le formulaire revient sur lui-même. --}}
        
        <button type="submit" class="btn btn-primary">
            @if($etape === $derniere)
                <i class="fas fa-check"></i> Terminer
            @else
                Continuer <i class="fas fa-arrow-right"></i>
            @endif
        </button>
        

        @if($etape > 1)
            <a href="{{ $routeEtape($etape - 1) }}" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
        @endif

        {{-- Celui qui revient d'un parcours déjà terminé n'est pas venu le
             refaire en entier : il complète un point et repart. Sans cette
             sortie, il devrait traverser les cinq étapes pour retrouver ses
             paramètres. --}}
        @if($entreprise->souscription_terminee_le || request()->routeIs('superadmin.*'))
            <a href="{{ $routeQuitter }}" class="btn btn-outline">
                <i class="fas fa-xmark"></i> Quitter
            </a>
        @endif

        <span class="vide" style="margin-left:auto; font-size:12.5px;">
            Étape {{ $etape }} sur {{ $derniere }} — vous pouvez quitter et reprendre.
        </span>
    </div>
</form>

@endsection

@section('scripts')
<script>
// Retest de production : choisir le domaine puis cliquer « Continuer » est un
// geste de trop — il n'y a qu'un domaine à choisir. Le clic sur la tuile
// mène à l'étape suivante ; le bouton reste pour qui navigue au clavier.
document.querySelectorAll('[data-etape-suivante]').forEach(function (radio) {
    // `click` et non `change` : un domaine déjà coché (parcours repris) ne
    // déclenche pas `change`, et le clic resterait sans effet.
    radio.addEventListener('click', function () {
        var form = document.getElementById('form-souscription');

        // Les deux types de domaines doivent se comporter comme un seul
        // groupe radio. Sans cela, un ancien domaine personnalisé peut rester
        // coché en même temps que le nouveau domaine du référentiel ; les deux
        // valeurs partent au serveur et l'ancien domaine est repris.
        if (radio.name === 'categorie_id') {
            form.querySelectorAll('input[name="domaine_personnalise"]').forEach(function (autre) {
                autre.checked = false;
            });
        } else if (radio.name === 'domaine_personnalise') {
            form.querySelectorAll('input[name="categorie_id"]').forEach(function (autre) {
                autre.checked = false;
            });
        }

        if (form.dataset.envoye) return;
        form.dataset.envoye = '1';
        form.submit();
    });
});

// Le bouton « Retour » du navigateur restaure la page telle qu'on l'a
// quittée, drapeau « déjà envoyé » compris : revenu à l'étape 1, le clic sur
// le domaine ne menait plus nulle part (retest du 14/10/2026). La page
// rendue par le cache repart de zéro.
// Création simple de catégories à l'étape 4.
// Création de secteurs personnalisés : la saisie est conservée et peut être sélectionnée.
(function(){
    var zone=document.getElementById('secteurs-personnalises');
    var bouton=document.getElementById('ajouter-secteur');
    if(!zone||!bouton)return;
    bouton.addEventListener('click',function(){
        var ligne=document.createElement('div');ligne.style.cssText='display:flex;gap:8px;align-items:center;margin-bottom:6px;';
        var input=document.createElement('input');input.type='text';input.name='secteurs_personnalises[]';input.maxLength=150;input.className='form-control';input.placeholder='Nom du secteur précis';
        var del=document.createElement('button');del.type='button';del.className='btn btn-outline-danger';del.setAttribute('data-retirer-saisie','');del.innerHTML='<i class="fas fa-trash"></i>';
        ligne.appendChild(input);ligne.appendChild(del);zone.appendChild(ligne);
    });
})();

(function () {
    var prochainIndexCategorie=@json(count($metiersCourants ?? []));
    document.querySelectorAll('[data-ajouter-categorie]').forEach(function(bouton){
        bouton.addEventListener('click',function(){
            var bloc=bouton.closest('.bloc-categories-secteur');
            var zone=bloc.querySelector('.nouvelles-categories-secteur');
            var index=prochainIndexCategorie++;
            var metier=zone.dataset.metier;
            var ligne=document.createElement('div');ligne.className='ligne-saisie';ligne.style.cssText='display:flex;gap:8px;align-items:center;margin-bottom:6px;';
            var input=document.createElement('input');input.type='text';input.maxLength=120;input.name='categories_personnalisees['+index+'][nom]';input.className='form-control';input.placeholder='Nom de la catégorie';
            var hidden=document.createElement('input');hidden.type='hidden';hidden.name='categories_personnalisees['+index+'][metier_configuration]';hidden.value=metier;
            var del=document.createElement('button');del.type='button';del.className='btn btn-outline-danger';del.setAttribute('data-retirer-saisie','');del.innerHTML='<i class="fas fa-trash"></i>';
            ligne.appendChild(input);ligne.appendChild(hidden);ligne.appendChild(del);zone.appendChild(ligne);
            zone.dataset.index=String(index+1);
        });
    });
    document.addEventListener('click',function(e){
        var del=e.target.closest('[data-retirer-saisie]');
        if(del){del.parentElement.remove();return;}
        var supprimerCategorie=e.target.closest('[data-supprimer-categorie]');
        if(supprimerCategorie){
            var form=document.getElementById('form-souscription');
            var champ=document.createElement('input');
            champ.type='hidden';
            champ.name='supprimer_categorie_personnalisee';
            champ.value=supprimerCategorie.getAttribute('data-supprimer-categorie');
            form.appendChild(champ);
            form.submit();
        }
    });
})();
(function () {
    var table=document.querySelector('.tableau-prix tbody');
    var bouton=document.getElementById('ajouter-article');
    if(!table||!bouton)return;
    var index=table.querySelectorAll('tr').length;
    var types=@json(\App\Modules\Admin\Modeles\Produit::TYPES);
    var sites=@json(isset($pointsDeVente) ? $pointsDeVente->map(fn($site)=>['id'=>$site->id,'nom'=>$site->nom])->values() : []);
    var categories=@json(isset($categoriesEntreprise) ? $categoriesEntreprise->map(fn($categorie)=>['id'=>$categorie->id,'nom'=>$categorie->nom,'domaine'=>$categorie->domaine_configuration])->values() : []);
    var avecStock=@json(isset($avecStock) && (bool) $avecStock);
    table.addEventListener('click',function(e){
        var del=e.target.closest('[data-retirer-article]');if(!del)return;
        var row=del.closest('tr');var id=row.querySelector('input[name$="[id]"]');
        if(id){var hidden=document.createElement('input');hidden.type='hidden';hidden.name='articles_supprimer[]';hidden.value=id.value;document.getElementById('form-souscription').appendChild(hidden);}
        row.remove();
    });
    bouton.addEventListener('click',function(){
        var vide=table.querySelector('[data-catalogue-vide]');
        if(vide) vide.remove();
        var i=index++;
        var tr=document.createElement('tr');
        var siteOptions='<option value="">Tous</option>'+sites.map(s=>'<option value="'+s.id+'">'+String(s.nom).replace(/[&<>"']/g,'')+'</option>').join('');
        var typeOptions='<option value="">Type d’article</option>'+Object.entries(types).map(([k,label])=>'<option value="'+k+'">'+label+'</option>').join('');
        var categorieOptions='<option value="">Choisir une catégorie</option>'+categories.map(c=>'<option value="'+c.id+'">'+String(c.nom).replace(/[&<>"']/g,'')+(c.domaine?' — '+String(c.domaine).replace(/[&<>"']/g,''):'')+'</option>').join('');
        tr.innerHTML='<td><span class="cpt" data-reference>ARTICLE</span></td>'+
          '<td><input required type="text" name="articles['+i+'][nom]" maxlength="255" placeholder="Nom de l’article"><input type="hidden" name="articles['+i+'][nouveau]" value="1"><select required class="form-control" name="articles['+i+'][type]">'+typeOptions+'</select></td>'+
          '<td><select required class="form-control" name="articles['+i+'][categorie_id]">'+categorieOptions+'</select></td>'+
          '<td><select name="articles['+i+'][point_de_vente_id]" class="form-control">'+siteOptions+'</select></td>'+
          '<td><input type="number" name="articles['+i+'][prix_achat]" min="0" step="1"></td>'+
          '<td><input type="number" name="articles['+i+'][prix_vente]" min="0" step="1"></td>'+
          '<td><button type="button" class="btn btn-outline-danger" data-retirer-article title="Retirer cette ligne"><i class="fas fa-trash"></i></button></td>';
        if(avecStock){var td=document.createElement('td');td.innerHTML='<input type="number" name="articles['+i+'][stock_initial]" min="0" step="1" placeholder="0">';tr.insertBefore(td,tr.lastElementChild);}
        table.appendChild(tr);
    });
})();
</script>
@endsection
