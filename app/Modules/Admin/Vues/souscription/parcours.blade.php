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

{{-- ── Le fil du parcours ── --}}
@php
    $titres = [1 => 'Domaine', 2 => 'Métier', 3 => 'Modules', 4 => 'Rayons', 5 => 'Prix'];
@endphp
<div class="sous-fil">
    @foreach($titres as $numero => $titre)
        @php
            $classe = $numero === $etape ? 'ici' : ($numero <= $entreprise->souscription_etape ? 'faite' : '');
        @endphp
        @if($numero <= $entreprise->souscription_etape + 1 && $numero !== $etape)
            <a href="{{ route('admin.souscription.index', ['etape' => $numero]) }}"
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

<form method="POST" action="{{ route('admin.souscription.enregistrer', $etape) }}">
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
                les rayons et les articles du nouveau domaine à ceux que vous avez déjà.
            </p>
        @endif

        {{-- Le référentiel absent laissait cette page muette : la question, le
             bouton, et rien entre les deux. L'utilisateur n'avait aucun moyen
             de savoir que le catalogue des domaines n'était pas chargé, ni que
             continuer était impossible — le formulaire exige un domaine que
             rien ne proposait. --}}
        @if($categories->isEmpty())
            <div class="referentiel-absent">
                <i class="fas fa-triangle-exclamation"></i>
                <div>
                    <strong>Le catalogue des domaines d'activité n'est pas chargé.</strong>
                    <p>La configuration ne peut pas commencer sans lui. Demandez à votre
                       administrateur de charger le référentiel de préparamétrage
                       (<code>php artisan db:seed --class=ReferentielSeeder</code>),
                       puis revenez sur cette page.</p>
                </div>
            </div>
        @else
        <div class="tuiles">
            @foreach($categories as $categorie)
            <label class="tuile">
                <input type="radio" name="categorie_id" value="{{ $categorie->id }}"
                       {{ ($choix['categorie_id'] ?? null) == $categorie->id ? 'checked' : '' }}>
                <div class="corps">
                    <div class="titre">{{ $categorie->nom }}</div>
                    <div class="detail">{{ $categorie->profils_count }} métier{{ $categorie->profils_count > 1 ? 's' : '' }}</div>
                </div>
            </label>
            @endforeach
        </div>
        @endif

    {{-- ══ 2. Le métier ══ --}}
    @elseif($etape === 2)
        <div class="sous-tete">
            <h2>Quel est votre métier ?</h2>
            <p>Plusieurs réponses sont possibles : une quincaillerie qui livre des chantiers
               coche les deux. Chaque métier apporte ses rayons et ses articles.</p>
        </div>

        @if(!empty($profilsAcquis))
            <p class="note-verrou">
                <i class="fas fa-circle-check"></i>
                Les métiers marqués <strong>déjà en place</strong> vous appartiennent : leurs rayons
                et leurs articles sont chez vous. Ils ne se retirent pas d'ici — vous
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
                        {{ $profil->familles_count }} rayons · {{ $profil->articles_count }} articles
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
        </div>

        <div class="autre">
            <label for="activite_autre">Aucun ne correspond ?</label>
            <p>Décrivez votre activité en quelques mots. Nous la prendrons en compte pour
               enrichir la liste ; en attendant, vous partirez d'un catalogue vide que vous
               remplirez à votre main.</p>
            <input type="text" id="activite_autre" name="activite_autre" class="form-control"
                   value="{{ old('activite_autre', $entreprise->activite_autre) }}"
                   placeholder="Ex. : atelier de reliure et dorure" maxlength="150">
        </div>

    {{-- ══ 3. Les modules ══ --}}
    @elseif($etape === 3)
        <div class="sous-tete">
            <h2>Ce que votre métier demande</h2>
            <p>Voici les modules que vos choix ouvrent. Décochez ce dont vous n'avez pas
               l'usage — vous pourrez les rouvrir à tout moment depuis vos paramètres.</p>
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
        @endphp

        @php
            $resteAChoisir = array_diff($modulesProposes, $modulesDejaOuverts ?? [], $structurels);
        @endphp
        @if(empty($resteAChoisir))
            <p class="note-verrou">
                <i class="fas fa-circle-check"></i>
                Tout ce que votre métier demande est <strong>déjà ouvert</strong> : il n'y a rien
                à choisir ici. Continuez vers l'étape suivante.
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
            @php
                $estStructurel = in_array($module, $structurels, true);
                $verrou        = $modulesVerrouilles[$module] ?? null;
                // Déjà ouvert : l'inscription en ouvre dix d'office, et l'étape
                // les présentait comme un choix à faire, cochés, en annonçant
                // leur activation. Ils sont acquis — c'est dans les paramètres
                // qu'on les referme, pas ici.
                $dejaOuvert    = !$estStructurel && $verrou === null && in_array($module, $modulesDejaOuverts ?? [], true);
                $fige          = $estStructurel || $verrou !== null || $dejaOuvert;
            @endphp
            <label class="ligne {{ $verrou || $dejaOuvert ? 'acquise' : '' }}">
                <input type="checkbox" name="modules[]" value="{{ $module }}"
                       {{ $fige || in_array($module, $choix['modules'] ?? $modulesProposes, true) ? 'checked' : '' }}
                       {{ $fige ? 'disabled' : '' }}>
                <div>
                    {{-- Le nom de la barre latérale, pas le code : la case
                         « Fne » commandait la section « Fiscalité & DGI », et
                         rien ne le disait. --}}
                    <div class="nom">{{ \App\Modules\Admin\Modeles\Entreprise::libelleModule($module) }}</div>
                    @if($verrou)
                        <div class="sous">{{ $verrou }}</div>
                    @elseif(isset($raisons[$module]))
                        <div class="sous">{{ $raisons[$module] }}</div>
                    @elseif($dejaOuvert)
                        <div class="sous">Déjà ouvert — il se referme depuis vos paramètres.</div>
                    @endif
                </div>
                @if($verrou)
                    <div class="droite"><span class="badge-acquis">en service</span></div>
                @elseif($dejaOuvert)
                    <div class="droite"><span class="badge-acquis">déjà ouvert</span></div>
                @endif
            </label>
            {{-- Une case désactivée n'est pas transmise : sans ce relais, valider
                 l'étape retirerait le module qu'on vient de dire indéracinable. --}}
            @if($fige)
                <input type="hidden" name="modules[]" value="{{ $module }}">
            @endif
            @endforeach
        </div>

    {{-- ══ 4. Les rayons ══ --}}
    @elseif($etape === 4)
        <div class="sous-tete">
            <h2>Vos rayons</h2>
            <p>Tout est coché : décochez ce que vous ne vendez pas. Chaque rayon apporte ses
               articles. Rien n'est définitif — vous pourrez en
               ajouter et en archiver ensuite.</p>
        </div>

        <div class="liste">
            @foreach($familles as $famille)
            <label class="ligne">
                <input type="checkbox" name="familles[]" value="{{ $famille->code }}" checked>
                <div>
                    <div class="nom">{{ $famille->nom }}</div>
                    <div class="sous">
                        {{ $famille->profil->nom }} ·
                        {{-- Le numéro de compte n'a de sens que pour qui tient
                             ses livres. Le préparamétrage, lui, se pose en base
                             quoi qu'il arrive : il servira le jour où la
                             comptabilité s'ouvre. --}}
                        @if($entreprise->comptabiliteOuverte())
                        <span class="cpt">{{ $famille->compte_vente ?? $famille->compte_achat ?? '—' }}</span>
                        {{ $famille->intituleCompte('compte_vente') ?? $famille->typeArticle->libelle }}
                        @else
                        {{ $famille->typeArticle->libelle }}
                        @endif
                    </div>
                </div>
                <div class="droite">{{ $famille->articles_count }} articles</div>
            </label>
            @endforeach
        </div>

    {{-- ══ 5. Prix et stock ══ --}}
    @elseif($etape === 5)
        @php
            // La colonne de stock n'a de sens que si le module est ouvert, qu'un
            // site existe pour l'accueillir, et qu'au moins un article se compte :
            // pour un cabinet comptable, dont tous les articles sont des
            // missions, elle serait une colonne de tirets.
            $avecStock = $siteDuStock && $articles->contains(fn ($a) => $a->estStockable());
            $colonnes  = $avecStock ? 6 : 5;
        @endphp

        <div class="sous-tete">
            <h2>Vos prix{{ $avecStock ? ' et votre stock de départ' : '' }}</h2>
            <p>Le catalogue livré ne porte aucun prix : ils varient selon la zone et la
               période. Renommez ce qui doit l'être et saisissez vos montants — vous pouvez
               aussi passer et les compléter article par article plus tard.
               @if($avecStock)
                   La dernière colonne reçoit les quantités comptées aujourd'hui
                   à « {{ $siteDuStock->nom }} » : c'est votre inventaire d'ouverture.
               @endif
            </p>
        </div>

        <div class="cadre-defilant">
            <table class="tableau-prix">
                <thead>
                    <tr><th>Référence</th><th>Article</th><th>Rayon</th>
                        <th style="width:130px;">Prix d'achat</th><th style="width:130px;">Prix de vente</th>
                        @if($avecStock)<th style="width:130px;">Stock de départ</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($articles as $i => $article)
                    <tr>
                        <td><span class="cpt">{{ $article->reference }}</span></td>
                        <td>
                            <input type="hidden" name="articles[{{ $i }}][id]" value="{{ $article->id }}">
                            <input type="text" name="articles[{{ $i }}][nom]" value="{{ $article->nom }}" maxlength="255">
                        </td>
                        <td>{{ $article->categorieRelation->nom ?? '—' }}</td>
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
                    </tr>
                    @empty
                    <tr><td colspan="{{ $colonnes }}" style="text-align:center; padding:26px; color:var(--text-3);">
                        Votre catalogue est vide — vous le remplirez depuis l'écran des produits.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <div class="pieds">
        {{-- Un bouton qui ne peut mener nulle part vaut mieux caché : sans
             domaine à choisir, le formulaire revient sur lui-même. --}}
        @unless($etape === 1 && $categories->isEmpty())
        <button type="submit" class="btn btn-primary">
            @if($etape === $derniere)
                <i class="fas fa-check"></i> Terminer
            @else
                Continuer <i class="fas fa-arrow-right"></i>
            @endif
        </button>
        @endunless

        @if($etape > 1)
            <a href="{{ route('admin.souscription.index', ['etape' => $etape - 1]) }}" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
        @endif

        {{-- Celui qui revient d'un parcours déjà terminé n'est pas venu le
             refaire en entier : il complète un point et repart. Sans cette
             sortie, il devrait traverser les cinq étapes pour retrouver ses
             paramètres. --}}
        @if($entreprise->souscription_terminee_le)
            <a href="{{ route('admin.entreprise.parametres') }}" class="btn btn-outline">
                <i class="fas fa-xmark"></i> Quitter
            </a>
        @endif

        <span class="vide" style="margin-left:auto; font-size:12.5px;">
            Étape {{ $etape }} sur {{ $derniere }} — vous pouvez quitter et reprendre.
        </span>
    </div>
</form>

@endsection
