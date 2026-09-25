@extends('admin::gabarits.application')
@section('titre', 'Paramètres de l\'entreprise')
@section('topbar_titre', 'Mon entreprise — Paramètres')

{{-- La grille vivait en style écrit dans la balise, ce qui interdisait toute
     règle de largeur : un style de balise l'emporte sur la feuille, même sous
     media query. Sur un portable, les deux colonnes restaient donc côte à côte
     et les champs devenaient illisibles. --}}
@section('styles')
    <style>
        .grille-parametres {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
        }

        .colonne-parametres {
            display: flex;
            flex-direction: column;
            gap: 20px;
            min-width: 0;
        }

        .pleine-largeur {
            grid-column: 1 / -1;
        }

        .conformite-corps {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 22px;
            align-items: start;
        }

        .conformite-etapes {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 22px;
        }

        @media (max-width: 1280px) {
            .conformite-corps { grid-template-columns: 1fr; }
        }

        @media (max-width: 1024px) {
            .grille-parametres { grid-template-columns: 1fr; }
            .conformite-etapes { grid-template-columns: 1fr; }
        }
    </style>
@endsection

@section('contenu')
    <div class="page-header">
        <div>
            <h1><i class="fas fa-building"></i> Paramètres de l'entreprise</h1>
            <p>Informations légales, fiscales et logos qui apparaissent sur vos factures</p>
        </div>
    </div>

    {{-- La page porte dix cartes sur deux colonnes et trois écrans de haut :
         on y cherchait un réglage en faisant défiler. Les ancres disent d'un
         coup d'œil ce qu'elle contient, et y mènent. Leur ordre suit celui des
         cartes à l'écran — colonne gauche, puis colonne droite, puis la
         conformité en pleine largeur — sans quoi un raccourci renverrait plus
         haut que le précédent. --}}
    <nav aria-label="Sections des paramètres"
         style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:22px;">
        @foreach([
            'identite'      => ['Identité', 'fa-info-circle'],
            'fiscal'        => ['Identité fiscale', 'fa-file-invoice'],
            'comptaflow'    => ['Liaison Comptaflow', 'fa-link'],
            'dgi'           => ['DGI & local', 'fa-map-marker-alt'],
            'tiers'         => ['Numérotation des tiers', 'fa-hashtag'],
            'compte-fne'    => ['Compte FNE', 'fa-id-card-clip'],
            'options'       => ['Options fiscales', 'fa-check-square'],
            'impression'    => ['Impression', 'fa-print'],
            'conformite'    => ['Conformité FNE', 'fa-clipboard-check'],
            'exercices'     => ['Exercices comptables', 'fa-calendar-alt'],
            'statut-fne'    => ['Statut FNE', 'fa-key'],
        ] as $ancre => [$libelle, $icone])
            <a href="#{{ $ancre }}"
               style="display:inline-flex;align-items:center;gap:7px;padding:7px 13px;border-radius:20px;
                      background:var(--bg3);border:1px solid var(--border);color:var(--text-2);
                      font-size:12px;font-weight:600;text-decoration:none;">
                <i class="fas {{ $icone }}" style="color:var(--primary);font-size:11px;"></i>{{ $libelle }}
            </a>
        @endforeach
    </nav>

    {{-- ══ Votre configuration ══

         Les secteurs d'activité se cochaient ici, dans une liste qui ne parlait
         pas au parcours de configuration : on pouvait déclarer « Santé » dans
         cet écran et souscrire au métier « Boulangerie » dans l'autre, et les
         deux réponses cohabitaient sans que rien ne le signale. Le domaine se
         choisit désormais **au parcours**, et se lit ici. --}}
    <div class="card" style="padding:22px 24px;margin-bottom:22px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">
            <div style="flex:1;min-width:280px;">
                <div
                    style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-sliders" style="color:var(--primary);"></i> Votre configuration
                </div>

                @php
                    // Les noms sont ceux de la barre latérale, pris à la même
                    // source. L'écran fabriquait le sien à partir du code —
                    // « Comptabilite » sans accent, « Fne » pour la section que
                    // le menu appelle « Fiscalité & DGI » — et l'utilisateur
                    // devait deviner quelle case commandait quelle section.
                    $nommer = fn ($m) => \App\Modules\Admin\Modeles\Entreprise::libelleModule($m);

                    $lignesConfig = [
                        ['Domaines', $configuration['domaines'] ?? []],
                        ['Métiers',  $configuration['metiers']  ?? []],
                        ['Modules ouverts', array_map($nommer, $configuration['modules'] ?? [])],
                    ];
                @endphp

                <div style="display:flex;flex-direction:column;gap:12px;">
                    @foreach($lignesConfig as [$intitule, $valeurs])
                        <div style="display:flex;gap:14px;align-items:flex-start;">
                            <span
                                style="font-size:12px;color:var(--text-3);min-width:120px;padding-top:3px;">{{ $intitule }}</span>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;flex:1;">
                                @forelse($valeurs as $valeur)
                                    <span
                                        style="display:inline-block;padding:4px 11px;border-radius:20px;background:var(--bg3);border:1px solid var(--border);font-size:12px;font-weight:600;">{{ $valeur }}</span>
                                @empty
                                    <span style="font-size:12px;color:var(--text-3);font-style:italic;">— pas encore
                                        choisi —</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(!empty($configuration['rouverts']))
                    <p
                        style="margin-top:14px;font-size:12px;line-height:1.6;color:#92400E;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:11px 14px;">
                        <i class="fas fa-rotate-left" style="color:#D97706;"></i>
                        <strong>{{ implode(', ', array_map($nommer, $configuration['rouverts'])) }}</strong>
                        {{ count($configuration['rouverts']) > 1 ? 'ont été rouverts' : 'a été rouvert' }} :
                        {{ count($configuration['rouverts']) > 1 ? 'ces modules portaient' : 'ce module portait' }}
                        vos données alors {{ count($configuration['rouverts']) > 1 ? 'qu\'ils étaient fermés' : 'qu\'il était fermé' }},
                        et les écrans où {{ count($configuration['rouverts']) > 1 ? 'elles se lisent avaient' : 'elles se lisent avaient' }}
                        disparu de votre menu.
                    </p>
                @endif

                @if(!empty($configuration['verrous']))
                    <p
                        style="margin-top:14px;font-size:12px;line-height:1.6;color:#065f46;background:rgba(5,150,105,.06);border:1px solid rgba(5,150,105,.22);border-radius:8px;padding:11px 14px;">
                        <i class="fas fa-lock" style="color:#059669;"></i>
                        {{ count($configuration['verrous']) }} module{{ count($configuration['verrous']) > 1 ? 's' : '' }}
                        porte{{ count($configuration['verrous']) > 1 ? 'nt' : '' }} déjà vos données
                        ({{ implode(', ', array_map($nommer, array_keys($configuration['verrous']))) }}).
                        Ils ne se referment plus : les fermer ne supprimerait rien, mais ferait disparaître de votre
                        menu les écrans où ces données se lisent.
                    </p>
                @endif
            </div>

            {{-- Le parcours de configuration n'était accessible qu'une fois, au
                 démarrage. Une entreprise qui ajoute un métier, ouvre un rayon ou
                 veut rouvrir un module n'avait plus aucun chemin pour y revenir.
                 Il se reprend ici, à l'étape qu'on veut : rien n'y est écrasé, et
                 ce qui a déjà été coché revient coché. --}}
            <div style="display:flex;flex-direction:column;gap:8px;align-items:stretch;min-width:210px;">
                {{-- `etape => 1` est indispensable. Sans elle, le parcours
                     s'ouvre à la dernière étape atteinte — la cinquième pour
                     une entreprise déjà configurée, c'est-à-dire l'écran des
                     prix. Le bouton servait donc à tout sauf à ce qu'on lui
                     demandait : ajouter un domaine ou un métier. --}}
                <a href="{{ route('admin.souscription.index', ['etape' => 1]) }}" class="btn btn-primary"
                    style="display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap;">
                    <i class="fas fa-plus"></i>
                    Ajouter une configuration
                </a>
                <small style="font-size:11px;color:var(--text-3);line-height:1.55;">
                    Le parcours repart du choix du domaine, puis les métiers, les modules, les
                    rayons et les prix. <strong>Rien de ce que vous avez déjà n'est perdu</strong> :
                    ajouter un domaine ou un métier n'efface jamais les précédents, et ce qui est
                    souscrit revient coché.
                </small>
            </div>
        </div>
    </div>

    @if(session('succes'))
        <div class="alert alert-success"
            style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:20px;color:#065f46;font-weight:500;">
            <i class="fas fa-check-circle" style="font-size:16px;color:#10b981;"></i>
            {{ session('succes') }}
        </div>
    @endif

    @if(!$entreprise->estInscriptionComplete())
        <div
            style="display:flex;align-items:center;justify-content:space-between;gap:16px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:12px;padding:16px 20px;margin-bottom:22px;color:#92400E;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span style="font-size:18px;color:#D97706;"><i class="fas fa-triangle-exclamation"></i></span>
                <div>
                    <h4 style="font-weight:700;font-size:14px;margin-bottom:2px;">Inscription incomplète</h4>
                    <p style="font-size:12px;color:#B45309;">Remplissez les champs marqués <span
                            style="color:#DC2626;font-weight:700;">*</span> pour finaliser l'inscription et débloquer toutes les
                        fonctionnalités.
                        {{-- Le secteur ne se coche plus dans ce formulaire : sans cette
                             phrase, une entreprise qui n'a pas fait son parcours resterait
                             « incomplète » en remplissant pourtant tous les champs visibles,
                             sans qu'aucun écran ne dise où aller. --}}
                        @if(empty($entreprise->secteur_activite))
                            <br><strong>Votre domaine d'activité n'est pas encore choisi</strong> — il se règle au
                            <a href="{{ route('admin.souscription.index') }}"
                                style="color:#92400E;text-decoration:underline;">parcours de configuration</a>.
                        @endif
                    </p>
                </div>
            </div>
            <span
                style="background:#FCD34D;color:#92400E;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;">{{ $entreprise->nom === '[PENDING_ONBOARDING]' ? 'Démarrage' : 'En cours' }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.entreprise.parametres.enregistrer') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        {{-- Les cartes tenaient toutes dans la colonne de gauche, sauf les
             logos et le récapitulatif : de « DGI & Local professionnel »
             jusqu'à « Impression des factures », la moitié droite de la
             page restait blanche sur quatre écrans de défilement. Les deux
             colonnes portent maintenant des hauteurs comparables, et la
             procédure de conformité — la plus longue — passe en pleine
             largeur sous elles. --}}
        <div class="grille-parametres">

            {{-- Colonne gauche — l'entreprise telle que la DGI la connaît --}}
            <div class="colonne-parametres">

                {{-- Informations générales --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span id="identite" style="scroll-margin-top:90px;"></span><i class="fas fa-info-circle" style="color:var(--primary);"></i> Informations générales
                    </div>
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        @php
                            $user = Auth::user();
                            // Si l'entreprise vient d'être créée via Google, vider le nom temporaire
                            $nomEntreprise = ($entreprise->nom === '[PENDING_ONBOARDING]') ? '' : $entreprise->nom;
                        @endphp
                        <div class="form-group">
                            <label class="form-label">Nom de l'entreprise <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="nom" class="form-control" value="{{ old('nom', $nomEntreprise) }}"
                                required placeholder="Ex: Commerce Général Ivoirien SARL">
                        </div>

                        {{-- Informations Gérant — pré-remplies depuis les infos de connexion --}}
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Nom du Gérant / Représentant <span
                                        style="color:var(--danger)">*</span></label>
                                <input type="text" name="gerant_nom" class="form-control"
                                    value="{{ old('gerant_nom', $entreprise->gerant_nom ?: $user->nom) }}"
                                    placeholder="Ex: Koné">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Prénom du Gérant <span
                                        style="color:var(--danger)">*</span></label>
                                <input type="text" name="gerant_prenom" class="form-control"
                                    value="{{ old('gerant_prenom', $entreprise->gerant_prenom ?: $user->prenom) }}"
                                    placeholder="Ex: Mamadou">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Fonction du Gérant <span
                                        style="color:var(--danger)">*</span></label>
                                <input type="text" name="gerant_fonction" class="form-control"
                                    value="{{ old('gerant_fonction', $entreprise->gerant_fonction) }}"
                                    placeholder="Ex: Directeur Général / Gérant">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">E-mail du gérant</label>
                                <input type="email" class="form-control" value="{{ $user->email }}" disabled
                                    style="background:var(--bg3);cursor:not-allowed;" title="Email du compte connecté">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Adresse physique</label>
                            <input type="text" name="adresse" class="form-control"
                                value="{{ old('adresse', $entreprise->adresse) }}"
                                placeholder="Ex: Cocody, Abidjan, Côte d'Ivoire">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Téléphone</label>
                                <input type="text" name="telephone" class="form-control"
                                    value="{{ old('telephone', $entreprise->telephone) }}"
                                    placeholder="Ex: +225 07 00 00 00">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">E-mail</label>
                                <input type="email" name="email" class="form-control"
                                    value="{{ old('email', $entreprise->email) }}"
                                    placeholder="Ex: contact@monentreprise.com">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">
                                    RCCM <span style="color:var(--danger)">*</span>
                                    <span style="font-size:10px;color:var(--text-3);font-weight:400;"> — Registre du Commerce et
                                        du Crédit Mobilier</span>
                                </label>
                                <input type="text" name="rccm" class="form-control" value="{{ old('rccm', $entreprise->rccm) }}"
                                    placeholder="Ex: CI-ABJ-03-2021-B13-05438">
                            </div>
                            {{-- Elle n'était demandée qu'au formulaire d'inscription,
                                 et ne se corrigeait plus ensuite : la passerelle
                                 Comptaflow retombait sur « SARL » pour tout le monde. --}}
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Forme juridique</label>
                                <select name="forme_juridique" class="form-control">
                                    <option value="">— Choisir —</option>
                                    @foreach(['Entreprise individuelle','SARL','SARL Unipersonnelle','SA','SAS','SNC','GIE','Coopérative','Association'] as $fj)
                                        <option value="{{ $fj }}" {{ old('forme_juridique', $entreprise->forme_juridique) === $fj ? 'selected' : '' }}>{{ $fj }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Informations fiscales --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        {{-- L'en-tête annonçait « Lecture seule » au-dessus de cinq
                             champs qui se saisissent tous, et dont deux — le NCC et
                             le régime — décident du code TVA transmis à la DGI.
                             Annoncer qu'on ne peut rien changer là où tout se change
                             est la pire des indications. --}}
                        <span id="fiscal" style="scroll-margin-top:90px;"></span><i class="fas fa-file-invoice" style="color:var(--primary);"></i> Identité fiscale
                    </div>
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">NCC — N° Compte Contribuable <span
                                        style="color:var(--danger)">*</span></label>
                                <input type="text" name="ncc" class="form-control"
                                    value="{{ old('ncc', $entreprise->ncc) }}" placeholder="Ex: 2169728N">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Régime d'imposition <span
                                        style="color:var(--danger)">*</span></label>
                                {{-- La liste vient du modele : elle vivait ici en dur,
                                     et trois autres ecrans en portaient chacun une
                                     version differente. TCE et RME manquaient a
                                     certaines, alors que la DGI les cite parmi les
                                     regimes ouvrant droit a l'exoneration legale. --}}
                                <select name="regime_imposition" class="form-control">
                                    <option value="">— Choisir un régime —</option>
                                    @foreach(\App\Modules\Admin\Modeles\Entreprise::REGIMES_IMPOSITION as $code => $libelle)
                                        <option value="{{ $code }}" {{ old('regime_imposition', $entreprise->regime_imposition) === $code ? 'selected' : '' }}>{{ $libelle }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Centre des impôts <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="centre_impots" class="form-control"
                                value="{{ old('centre_impots', $entreprise->centre_impots) }}"
                                placeholder="Ex: 2 PLATEAUX 3">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Références bancaires</label>
                            <textarea name="ref_bancaire" class="form-control" rows="3"
                                placeholder="Ex: Établissement : SGBCI — N° compte : 00123456789">{{ old('ref_bancaire', $entreprise->ref_bancaire) }}</textarea>
                            <small style="color:var(--text-3);font-size:11px;">Ces informations apparaîtront en bas de vos
                                factures.</small>
                        </div>
                        {{-- === LIAISON COMPTAFLOW === --}}
                        <div
                            style="border:2px solid {{ $entreprise->comptaflow_sync_status === 'active' ? '#10b981' : 'var(--border)' }};border-radius:12px;padding:18px;background:{{ $entreprise->comptaflow_sync_status === 'active' ? '#f0fdf4' : 'var(--bg3)' }};margin-top:4px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                <div
                                    style="font-size:12px;font-weight:700;color:{{ $entreprise->comptaflow_sync_status === 'active' ? '#065f46' : 'var(--text-2)' }};text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:8px;">
                                    <i class="fas fa-link"
                                        style="color:{{ $entreprise->comptaflow_sync_status === 'active' ? '#10b981' : 'var(--text-3)' }};"></i>
                                    <span id="comptaflow" style="scroll-margin-top:90px;"></span>Liaison COMPTAFLOW
                                </div>
                                @if($entreprise->comptaflow_sync_status === 'active')
                                    <span
                                        style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:30px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:5px;">
                                        <span
                                            style="width:7px;height:7px;background:#10b981;border-radius:50%;display:inline-block;animation:pulse 2s infinite;"></span>
                                        Active
                                    </span>
                                @else
                                    <span
                                        style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:30px;font-size:11px;font-weight:700;">
                                        Non configurée
                                    </span>
                                @endif
                            </div>

                            @if($entreprise->comptaflow_sync_status === 'active')
                                <div
                                    style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;font-size:12px;">
                                    <div style="background:white;border-radius:8px;padding:8px 12px;border:1px solid #d1fae5;">
                                        <div style="color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;">
                                            Dernière sync</div>
                                        <div style="font-weight:700;color:#065f46;">
                                            {{ $entreprise->comptaflow_last_sync_at ? \Carbon\Carbon::parse($entreprise->comptaflow_last_sync_at)->format('d/m/Y H:i') : '—' }}
                                        </div>
                                    </div>
                                    <div style="background:white;border-radius:8px;padding:8px 12px;border:1px solid #d1fae5;">
                                        <div style="color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;">
                                            ID COMPTAFLOW</div>
                                        <div style="font-weight:700;color:#065f46;">
                                            #{{ $entreprise->comptaflow_company_id ?? '—' }}</div>
                                    </div>
                                </div>
                            @endif

                            {{-- Le champ « Clé de synchronisation COMPTAFLOW »
                                 était ici, en saisie libre, avec la consigne
                                 d'aller la chercher sur Comptaflow. Coller la
                                 clé d'une autre entreprise ouvrait la liaison
                                 vers ses livres : le secret partagé est détenu
                                 par le serveur, il ne dit pas qui appelle.
                                 La liaison se demande en bas de page ; la clé
                                 est délivrée, jamais saisie. --}}
                            <div style="font-size:12px;color:var(--text-2);line-height:1.6;">
                                @if($entreprise->liaisonComptaflowActive())
                                    <i class="fas fa-circle-check" style="color:#10b981;"></i>
                                    Vos écritures partent vers votre dossier comptable
                                    <strong>#{{ $entreprise->comptaflow_company_id }}</strong>.
                                @elseif($entreprise->demandeComptaflowEnAttente())
                                    <i class="fas fa-hourglass-half" style="color:#d97706;"></i>
                                    Votre demande de dossier comptable attend d'être validée.
                                @else
                                    <i class="fas fa-circle-info" style="color:var(--text-3);"></i>
                                    Aucun dossier comptable relié.
                                @endif
                                <a href="#comptaflow-liaison" style="color:var(--primary);font-weight:600;">Voir le détail</a>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── Section DGI & Local ── --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span id="dgi" style="scroll-margin-top:90px;"></span><i class="fas fa-map-marker-alt" style="color:var(--primary);"></i> DGI & Local professionnel
                    </div>
                    <div style="display:flex;flex-direction:column;gap:14px;">

                        <div class="form-group">
                            {{-- L'IDU n'est pas exige par l'API FNE : aucun champ du
                            referentiel ne le reprend. L'asterisque laissait croire
                            le contraire et bloquait des entreprises qui n'en ont
                            pas. --}}
                            <label class="form-label">
                                IDU — Identifiant Unique DGI
                            </label>
                            <input type="text" name="idu" class="form-control" value="{{ old('idu', $entreprise->idu) }}"
                                placeholder="Ex: CI-001-2025-A123456">
                            <small style="color:var(--text-3);font-size:11px;">Cet identifiant apparaît sur chaque facture
                                normalisée FNE.</small>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Commune</label>
                                <input type="text" name="commune" class="form-control"
                                    value="{{ old('commune', $entreprise->commune) }}" placeholder="Ex: COCODY">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Quartier</label>
                                <input type="text" name="quartier" class="form-control"
                                    value="{{ old('quartier', $entreprise->quartier) }}"
                                    placeholder="Ex: Angré 8ème Tranche">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Référence Cadastrale</label>
                            <input type="text" name="reference_cadastrale" class="form-control"
                                value="{{ old('reference_cadastrale', $entreprise->reference_cadastrale) }}"
                                placeholder="Ex: Section B, Parcelle 042">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Propriétaire du local professionnel</label>
                            <input type="text" name="proprietaire_local" class="form-control"
                                value="{{ old('proprietaire_local', $entreprise->proprietaire_local) }}"
                                placeholder="Ex: SCI IMMOBILIERE COCODY">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Seuil d'alerte stickers <span style="color:#E53E3E">*</span>
                            </label>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <input type="number" name="sticker_solde_alerte" class="form-control"
                                    value="{{ old('sticker_solde_alerte', $entreprise->sticker_solde_alerte ?? 5) }}"
                                    min="1" max="9999" style="max-width:120px;">
                                <small style="color:var(--text-3);font-size:12px;">sticker(s) restants → notification
                                    d'alerte</small>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- ── Numérotation des tiers ──

                     Le numéro de tiers n'est pas le compte général. 411000 est
                     le compte collectif « Clients » du plan comptable ; 411001
                     ou 411KONE désigne un client précis. Les confondre fait
                     remonter, dans le relevé d'un client, le solde de tous. --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span id="tiers" style="scroll-margin-top:90px;"></span><i class="fas fa-hashtag" style="color:var(--primary);"></i> Numérotation des tiers
                    </div>

                    <div style="font-size:12px;color:var(--text-3);margin-bottom:14px;line-height:1.6;">
                        Le système attribue lui-même le numéro d'un client ou d'un fournisseur —
                        il ne se saisit pas. Vous choisissez seulement sa forme.
                        <strong>Ce réglage doit être le même que dans Comptaflow</strong>
                        (Configuration &rsaquo; Type d'identifiant tiers) : la passerelle retrouve un
                        tiers par son numéro exact, et deux conventions différentes feraient
                        retomber chaque écriture sur son compte collectif.
                        Les fiches déjà créées gardent leur numéro.
                    </div>

                    <div style="display:flex;flex-direction:column;gap:10px;">
                        @foreach(\App\Modules\Admin\Services\NumerotationTiersService::CONVENTIONS as $cle => $libelle)
                        <label
                            style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;padding:14px;background:var(--bg3);border-radius:10px;border:1px solid var(--border);">
                            <input type="radio" name="numerotation_tiers" value="{{ $cle }}"
                                {{ old('numerotation_tiers', $entreprise->numerotation_tiers ?? \App\Modules\Admin\Services\NumerotationTiersService::NUMERIQUE) === $cle ? 'checked' : '' }}
                                style="margin-top:3px;width:16px;height:16px;cursor:pointer;">
                            <div>
                                <div style="font-weight:600;font-size:13px;color:var(--text);">{{ $libelle }}</div>
                                <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
                                    @if($cle === \App\Modules\Admin\Services\NumerotationTiersService::NUMERIQUE)
                                        Le préfixe du collectif, puis un compteur :
                                        <code>410001</code>, <code>410002</code> pour les clients,
                                        <code>400001</code> pour les fournisseurs. Jamais d'homonyme,
                                        mais il faut ouvrir la fiche pour savoir de qui il s'agit.
                                    @else
                                        Le préfixe, trois lettres du nom, puis un compteur :
                                        <code>41KON1</code>, <code>40KOF1</code>. Lisible directement
                                        en grand livre ; deux Koné reçoivent <code>41KON1</code> et
                                        <code>41KON2</code>.
                                    @endif
                                </div>
                            </div>
                        </label>
                        @endforeach
                    </div>

                    <div style="margin-top:12px;font-size:12px;color:var(--text-3);line-height:1.6;">
                        Le numéro fait {{ \App\Modules\Admin\Services\NumerotationTiersService::LONGUEUR }} caractères,
                        préfixe compris — la même longueur que <code>tier_digits</code> dans Comptaflow.
                        Le préfixe tient sur deux chiffres&nbsp;: <code>41</code> pour un client rattaché
                        au <code>411000</code>, <code>40</code> pour un fournisseur rattaché au
                        <code>401000</code>. <strong>Le numéro de tiers n'est pas le compte
                        collectif</strong> : le premier désigne une personne, le second regroupe
                        tout le monde.
                    </div>
                </div>

                {{-- Les pièces refusées par la DGI. Elles vivaient dans le menu
                     « Fiscalité & DGI », comme un écran qu'on visite. Ce n'en
                     est pas un : c'est une alerte qu'on traite quand elle
                     survient, et elle relève de la configuration DGI. --}}
                @php
                    $rejetsOuverts = \App\Modules\Admin\Modeles\FneRejet::where('entreprise_id', $entreprise->id)
                        ->whereIn('statut', ['ouvert', 'diagnostique'])->count();
                @endphp
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span id="rejets" style="scroll-margin-top:90px;"></span><i class="fas fa-triangle-exclamation" style="color:var(--primary);"></i> Pièces refusées par la DGI
                    </div>

                    <div style="font-size:12px;color:var(--text-3);margin-bottom:14px;line-height:1.6;">
                        Une pièce que la plateforme a examinée puis refusée. Tant que la cause
                        n'est pas levée, elle ne repart pas — et le plus souvent, la cause est
                        un point de vente qui ne porte pas le même nom des deux côtés.
                        @if($rejetsOuverts > 0)
                            <br><strong style="color:#c2410c;">{{ $rejetsOuverts }} pièce(s) attendent d'être traitées.</strong>
                        @else
                            <br><strong style="color:#15803d;">Aucune pièce en attente.</strong>
                        @endif
                    </div>

                    <a href="{{ route('admin.fne.rejets') }}" class="btn btn-outline">
                        <i class="fas fa-triangle-exclamation"></i> Voir les pièces refusées
                        @if($rejetsOuverts > 0)
                            <span style="background:#E53E3E;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;margin-left:6px;font-weight:700;">{{ $rejetsOuverts }}</span>
                        @endif
                    </a>
                </div>

            </div>{{-- /colonne gauche --}}

            {{-- Colonne droite — ce que Selflow en fait : la plateforme FNE,
                 les options constatées, les logos et l'impression --}}
            <div class="colonne-parametres">

                {{-- Logos --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-image" style="color:var(--primary);"></i> Logos (affichés sur les factures)
                    </div>

                    {{-- Logo principal --}}
                    <div style="margin-bottom:20px;">
                        <label class="form-label" style="margin-bottom:10px;display:block;">Logo principal de
                            l'entreprise</label>
                        @if($entreprise->logo_path)
                            <div
                                style="margin-bottom:10px;padding:12px;background:var(--bg3);border-radius:8px;display:flex;align-items:center;gap:12px;">
                                <img src="{{ (str_starts_with($entreprise->logo_path, 'http://') || str_starts_with($entreprise->logo_path, 'https://')) ? $entreprise->logo_path : Storage::disk('public')->url($entreprise->logo_path) }}"
                                    alt="Logo entreprise"
                                    style="max-height:60px;max-width:140px;object-fit:contain;border-radius:4px;">
                                <span style="font-size:12px;color:var(--text-2);">Logo actuel</span>
                            </div>
                        @else
                            <div
                                style="margin-bottom:10px;padding:16px;background:var(--bg3);border-radius:8px;text-align:center;border:1.5px dashed var(--border);">
                                <i class="fas fa-image"
                                    style="font-size:28px;color:var(--text-3);margin-bottom:6px;display:block;"></i>
                                <span style="font-size:12px;color:var(--text-3);">Aucun logo défini</span>
                            </div>
                        @endif
                        <input type="file" name="logo" id="logo" class="form-control"
                            accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/webp" style="margin-top:8px;">
                        <small style="color:var(--text-3);font-size:11px;">PNG, JPG ou SVG · Max 2 Mo. Ce logo apparaît en
                            haut à gauche des factures.</small>
                    </div>

                    {{-- Logo FNE / secondaire --}}
                    <div>
                        <label class="form-label" style="margin-bottom:10px;display:block;">Logo secondaire (FNE,
                            certification, etc.)</label>
                        @if($entreprise->logo_fne_path)
                            <div
                                style="margin-bottom:10px;padding:12px;background:var(--bg3);border-radius:8px;display:flex;align-items:center;gap:12px;">
                                <img src="{{ (str_starts_with($entreprise->logo_fne_path, 'http://') || str_starts_with($entreprise->logo_fne_path, 'https://')) ? $entreprise->logo_fne_path : Storage::disk('public')->url($entreprise->logo_fne_path) }}"
                                    alt="Logo FNE"
                                    style="max-height:60px;max-width:140px;object-fit:contain;border-radius:4px;">
                                <span style="font-size:12px;color:var(--text-2);">Logo actuel</span>
                            </div>
                        @else
                            <div
                                style="margin-bottom:10px;padding:16px;background:var(--bg3);border-radius:8px;text-align:center;border:1.5px dashed var(--border);">
                                <i class="fas fa-award"
                                    style="font-size:28px;color:var(--text-3);margin-bottom:6px;display:block;"></i>
                                <span style="font-size:12px;color:var(--text-3);">Aucun logo secondaire défini</span>
                            </div>
                        @endif
                        <input type="file" name="logo_fne" id="logo_fne" class="form-control"
                            accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/webp" style="margin-top:8px;">
                        <small style="color:var(--text-3);font-size:11px;">PNG, JPG ou SVG · Max 2 Mo. Peut être un label
                            qualité, logo FNE, etc.</small>
                    </div>
                </div>

                {{-- ── Compte sur la plateforme FNE ──
                     Pose la question une fois, puis affiche ce qui manque selon
                     la reponse : reporter les informations d'un espace existant,
                     ou rassembler celles qu'il faut pour l'ouvrir. --}}
                @php
                    $aCompteFne = $entreprise->possede_compte_fne;

                    // La liste vit sur le modèle : le tableau FNE du
                    // superadministrateur la lit aussi, pour voir à qui il
                    // manque quoi avant de configurer une clé.
                    $infosFne = $entreprise->informationsFne();
                    $manquants = $entreprise->informationsFneManquantes();
                @endphp

                <div class="card" style="padding:24px;">
                    <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                        <span id="compte-fne" style="scroll-margin-top:90px;"></span><i class="fas fa-id-card-clip" style="color:var(--primary);"></i> Compte sur la plateforme FNE
                    </div>

                    <p style="font-size:12px;color:var(--text-3);line-height:1.6;margin-bottom:14px;">
                        Selflow établit vos factures, la plateforme FNE les certifie. Les deux
                        doivent connaître la même entreprise, sous les mêmes noms.
                    </p>

                    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;border:1px solid {{ $aCompteFne === true ? 'var(--primary)' : 'var(--border)' }};background:{{ $aCompteFne === true ? '#eff6ff' : 'var(--bg3)' }};cursor:pointer;font-size:13px;font-weight:600;">
                            <input type="radio" name="possede_compte_fne" value="1" {{ $aCompteFne === true ? 'checked' : '' }} onchange="this.form.requestSubmit ? null : null">
                            J'ai déjà un compte FNE
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;border:1px solid {{ $aCompteFne === false ? 'var(--primary)' : 'var(--border)' }};background:{{ $aCompteFne === false ? '#eff6ff' : 'var(--bg3)' }};cursor:pointer;font-size:13px;font-weight:600;">
                            <input type="radio" name="possede_compte_fne" value="0" {{ $aCompteFne === false ? 'checked' : '' }}>
                            Je n'en ai pas encore
                        </label>
                    </div>

                    @if($aCompteFne === false)
                        <div style="padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12.5px;color:#1e40af;line-height:1.7;margin-bottom:14px;">
                            <strong>Nous nous chargeons de l'inscription pour vous.</strong>
                            Renseignez simplement les informations ci-dessous : ce sont celles
                            que la DGI exige pour ouvrir un compte, et elles serviront à créer
                            votre espace FNE.
                            <br>
                            Les éléments qui n'existent pas encore — clé API, numéro de compte
                            attribué par la plateforme — seront complétés une fois le compte
                            ouvert. Vous n'avez aucune démarche à faire de votre côté.
                        </div>
                    @elseif($aCompteFne === true)
                        <div style="padding:12px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:12.5px;color:#92400e;line-height:1.7;margin-bottom:14px;">
                            <strong>Reportez ici les informations de votre espace FNE, à l'identique.</strong>
                            Un écart — une raison sociale abrégée, un point de vente nommé
                            autrement — et la plateforme rejette la facture ou la certifie sous
                            un autre libellé que le vôtre.
                        </div>
                    @endif

                    @if($aCompteFne !== null)
                        <div style="font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:10px;">
                            Informations requises
                            @if($manquants > 0)
                                <span style="font-weight:600;color:#b45309;">— {{ $manquants }} à compléter</span>
                            @else
                                <span style="font-weight:600;color:#047857;">— toutes renseignées</span>
                            @endif
                        </div>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            @foreach($infosFne as $info)
                                @php $renseigne = filled($info['valeur']); @endphp
                                <div style="display:flex;gap:10px;align-items:flex-start;padding:9px 11px;border-radius:8px;background:{{ $renseigne ? '#f8fafc' : '#fffbeb' }};border:1px solid {{ $renseigne ? 'var(--border)' : '#fde68a' }};">
                                    <i class="fas {{ $renseigne ? 'fa-circle-check' : 'fa-circle-exclamation' }}" style="color:{{ $renseigne ? '#10b981' : '#d97706' }};font-size:13px;margin-top:2px;"></i>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:12.5px;font-weight:600;color:var(--text);">
                                            {{ $info['champ'] }}
                                            @if($renseigne)
                                                <span style="font-weight:500;color:var(--text-3);"> · {{ \Illuminate\Support\Str::limit($info['valeur'], 40) }}</span>
                                            @endif
                                        </div>
                                        <div style="font-size:11.5px;color:var(--text-3);line-height:1.5;margin-top:1px;">{{ $info['note'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <p style="font-size:11.5px;color:var(--text-3);line-height:1.6;margin-top:12px;">
                            La clé API n'est pas saisie ici : elle est enregistrée par
                            l'administrateur Selflow une fois délivrée par la DGI.
                        </p>
                    @endif
                </div>

                {{-- ── Options fiscales ──
                Ces deux cases existent aussi dans les paramètres de la
                plateforme FNE, et c'est là qu'elles font foi. L'API ne les
                expose pas : Selflow ne peut ni les lire ni les modifier.
                Elles servent donc à noter ici l'état constaté chez la DGI,
                en attendant que l'API le communique. --}}
                                {{-- Les libellés d'écriture : ce que le journal dira d'une
                     opération. C'était un écran du menu Comptabilité, où il
                     n'avait rien à faire — on ne le consulte pas, on le règle
                     une fois. --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span id="libelles" style="scroll-margin-top:90px;"></span><i class="fas fa-pen-nib" style="color:var(--primary);"></i> Libellés d'écriture
                    </div>

                    <div style="font-size:12px;color:var(--text-3);margin-bottom:14px;line-height:1.6;">
                        Ce que le journal écrit en face de chaque opération — une vente, un
                        encaissement, un achat. Les modèles sont propres à votre entreprise ;
                        sans réglage, Selflow emploie l'intitulé du compte.
                    </div>

                    <a href="{{ route('admin.comptabilite.libelles') }}" class="btn btn-outline">
                        <i class="fas fa-pen-nib"></i> Régler les libellés d'écriture
                    </a>
                </div>

<div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span id="options" style="scroll-margin-top:90px;"></span><i class="fas fa-check-square" style="color:var(--primary);"></i> Options fiscales
                    </div>

                    <div
                        style="font-size:12px;color:var(--text-3);line-height:1.6;margin-bottom:14px;padding:10px 12px;background:#eff6ff;border-radius:8px;border:1px solid #bfdbfe;">
                        <i class="fas fa-circle-info" style="color:#2563eb;"></i>
                        Ces options se règlent sur la <strong>plateforme FNE</strong>, et c'est
                        là qu'elles s'appliquent. Reportez ici l'état que vous y avez
                        constaté : l'API ne le communique pas encore.
                    </div>

                    <div style="display:flex;flex-direction:column;gap:14px;">

                        {{-- Le timbre de quittance ne se règle plus ici.
                             Deux raisons. C'est un réglage de la plateforme FNE,
                             et la configuration de la plateforme appartient au
                             superadministrateur, comme les clés. Et surtout,
                             cette case n'a jamais été informative : elle décide
                             si le droit de timbre est réclamé au client, donc
                             du net à payer imprimé sur la facture et du montant
                             débité en caisse. La cocher à tort faisait payer au
                             client un droit que la DGI ne retenait pas. --}}
                        <div
                            style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:var(--bg3);border-radius:10px;border:1px solid var(--border);">
                            <i class="fas {{ $entreprise->timbre_quittance ? 'fa-circle-check' : 'fa-circle-minus' }}"
                                style="margin-top:3px;font-size:16px;color:{{ $entreprise->timbre_quittance ? 'var(--success)' : 'var(--text-3)' }};"></i>
                            <div>
                                <div style="font-weight:600;font-size:13px;color:var(--text);">
                                    Timbre de quittance —
                                    <span style="color:{{ $entreprise->timbre_quittance ? 'var(--success)' : 'var(--text-3)' }};">{{ $entreprise->timbre_quittance ? 'appliqué' : 'non appliqué' }}</span>
                                    <span
                                        style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#92400e;background:#fef3c7;border-radius:4px;padding:1px 6px;margin-left:6px;">Réglé par Selflow</span>
                                </div>
                                <div style="font-size:12px;color:var(--text-3);margin-top:2px;line-height:1.6;">
                                    Ce droit est établi au barème de l'article 873 du CGI et
                                    <strong>réclamé au client sur les règlements en espèces</strong> :
                                    il entre dans le net à payer de la facture et dans l'écriture de
                                    caisse. Il reflète l'option cochée sur votre espace FNE, que
                                    l'API ne communique pas — c'est donc votre administrateur
                                    Selflow qui la reporte, en même temps qu'il configure votre clé.
                                    <br>
                                    Si l'état affiché ne correspond pas à celui de votre espace FNE,
                                    signalez-le : une facture annoncerait un timbre que la
                                    plateforme ne retiendra pas, ou l'inverse.
                                </div>
                            </div>
                        </div>

                        {{-- Le BAPA était une case à cocher. Elle ne commandait
                             rien : aucune ligne du code ne la lisait, hors le
                             résumé de cette page. Le bordereau se choisit à la
                             saisie de l'achat, et c'est l'espace FNE de
                             l'entreprise qui l'autorise ou le refuse. Une case
                             qui paraît commander ce qu'elle ne commande pas est
                             pire qu'une case absente : elle devient une
                             information, qui dit où le réglage se trouve
                             vraiment. --}}
                        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;background:var(--bg3);border-radius:10px;border:1px solid var(--border);">
                            <i class="fas fa-circle-info" style="margin-top:3px;color:var(--primary);"></i>
                            <div>
                                <div style="font-weight:600;font-size:13px;color:var(--text);">Bordereau d'Achat de Produits
                                    Agricoles (BAPA)</div>
                                <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
                                    Le bordereau se choisit pièce par pièce, à la saisie de l'achat.
                                    Son ouverture ne se règle pas ici mais sur <strong>votre espace
                                    FNE</strong> : si l'option n'y est pas cochée, la plateforme
                                    refusera le bordereau, quoi que Selflow en dise.
                                </div>
                            </div>
                        </div>

                        {{-- Quand certifier : dès l'émission, ou à la main.
                             Une seule case, et non deux. Le reçu et la facture
                             empruntent la même porte — même envoi, même code QR,
                             même sticker ; seul le format d'impression les
                             sépare. Deux réglages pour une seule décision ne
                             disaient pas lequel commandait la pièce qu'on avait
                             sous les yeux. Les deux colonnes restent en base :
                             la case y écrit la même valeur. --}}
                        <label
                            style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;padding:14px;background:var(--bg3);border-radius:10px;border:1px solid var(--border);">
                            <input type="checkbox" name="normalisation_auto" value="1"
                                {{ old('normalisation_auto', $entreprise->normalisation_auto_factures ?? true) ? 'checked' : '' }}
                                style="margin-top:3px;width:16px;height:16px;cursor:pointer;">
                            <div>
                                <div style="font-weight:600;font-size:13px;color:var(--text);">
                                    Normaliser automatiquement les factures et les reçus
                                </div>
                                <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
                                    Cochée, chaque pièce part à la DGI dès son émission — facture
                                    comme reçu. Décochée, elle reste enregistrée et porte la mention
                                    <em>En attente</em> dans la liste des ventes ; vous la normalisez
                                    vous-même, après vérification, par le bouton
                                    <strong>Normaliser</strong>.
                                    <strong>Une pièce certifiée ne se reprend pas.</strong>
                                </div>
                            </div>
                        </label>

                    </div>
                </div>

                {{-- ── Impression des factures ── --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span id="impression" style="scroll-margin-top:90px;"></span><i class="fas fa-print" style="color:var(--primary);"></i> Impression des factures
                    </div>
                    <div style="display:flex;flex-direction:column;gap:14px;">
                        <div class="form-group">
                            <label class="form-label">Pied de page des factures</label>
                            <textarea name="pied_de_page_facture" id="piedDePageFactureInput" class="form-control" rows="3"
                                maxlength="248" oninput="majCompteurParametre('piedDePageFacture')"
                                placeholder="Ex: Merci pour votre confiance. Paiement à 30 jours. Pénalités de retard : 1,5% / mois.">{{ old('pied_de_page_facture', $entreprise->pied_de_page_facture) }}</textarea>
                            <small style="color:var(--text-3);font-size:11px;">
                                Ce texte apparaît en bas de chaque facture imprimée et est transmis à la DGI.
                                <span id="piedDePageFactureCompteur">0</span>/248 caractères.
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Autres mentions légales</label>
                            <textarea name="facture_autres_mentions" id="factureAutresMentionsInput" class="form-control"
                                rows="3" maxlength="248" oninput="majCompteurParametre('factureAutresMentions')"
                                placeholder="Ex: Capital social : 1 000 000 FCFA — Forme juridique : SARL">{{ old('facture_autres_mentions', $entreprise->facture_autres_mentions) }}</textarea>
                            <small style="color:var(--text-3);font-size:11px;">
                                Mentions additionnelles : capital social, forme juridique, etc. Transmises à la DGI.
                                <span id="factureAutresMentionsCompteur">0</span>/248 caractères.
                            </small>
                            <script>
                                function majCompteurParametre(prefixe) {
                                    const champ = document.getElementById(prefixe + 'Input');
                                    const compteur = document.getElementById(prefixe + 'Compteur');
                                    if (champ && compteur) compteur.textContent = champ.value.length;
                                }
                                document.addEventListener('DOMContentLoaded', function () {
                                    majCompteurParametre('piedDePageFacture');
                                    majCompteurParametre('factureAutresMentions');
                                });
                            </script>
                        </div>
                    </div>
                </div>

                {{-- Prévisualisation résumé --}}
                <div class="card" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-eye" style="color:var(--primary);"></i> Récapitulatif actuel
                    </div>
                    <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;">
                        @php
                            // Le récapitulatif taisait la forme juridique et
                            // l'IDU, deux informations que la page demande et
                            // qu'on vient justement vérifier ici.
                            $infos = [
                                ['Forme juridique', $entreprise->forme_juridique],
                                ['NCC', $entreprise->ncc],
                                ['Régime', $entreprise->regime_imposition],
                                ['Centre des impôts', $entreprise->centre_impots],
                                ['RCCM', $entreprise->rccm],
                                ['IDU', $entreprise->idu],
                                ['Téléphone', $entreprise->telephone],
                                ['E-mail', $entreprise->email],
                            ];
                        @endphp
                        @foreach($infos as [$label, $valeur])
                            <div
                                style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:0.5px solid var(--border);">
                                <span style="color:var(--text-2);">{{ $label }}</span>
                                <span style="font-weight:600;color:{{ $valeur ? 'var(--text)' : 'var(--text-3)' }};">
                                    {{ $valeur ?? '— Non renseigné —' }}
                                </span>
                            </div>
                        @endforeach
                        @if($entreprise->ref_bancaire)
                            <div
                                style="padding:8px;background:var(--bg3);border-radius:6px;font-size:12px;color:var(--text-2);margin-top:4px;">
                                <strong>Références bancaires :</strong><br>{{ $entreprise->ref_bancaire }}
                            </div>
                        @endif
                    </div>
                </div>

            </div>{{-- /colonne droite --}}

                {{-- ── Procédure de conformité FNE ──
                Ce qui suit n'est pas un rappel decoratif : chaque point
                correspond a un cas ou une facture part chez la DGI avec
                des montants ou des mentions differents de ceux etablis
                ici. On les a tous rencontres a la mise au point. --}}
                <div class="card pleine-largeur" style="padding:24px;">
                    <div
                        style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                        <span id="conformite" style="scroll-margin-top:90px;"></span><i class="fas fa-clipboard-check" style="color:var(--primary);"></i> Procédure à suivre — conformité
                        FNE
                    </div>
                    {{-- La phrase d'introduction disait « Selflow établit vos
                         factures et les certifient directement auprès de la
                         DGI. Les deux doivent dire la même chose. » — reste
                         d'une version où Selflow et la plateforme étaient deux
                         systèmes à tenir d'accord. Depuis, Selflow certifie
                         lui-même ; « les deux » ne désignait plus rien. --}}
                    <p style="font-size:12px;color:var(--text-3);line-height:1.6;margin-bottom:16px;">
                        Selflow établit vos factures et les envoie lui-même à la plateforme de la
                        DGI, qui les certifie. Ce que vous renseignez ici part avec elles : ces six
                        points sont ceux qui, négligés, font revenir une pièce certifiée
                        <strong>différente de celle que vous avez établie</strong> — ou pas de pièce
                        du tout.
                    </p>

                    @php
                        // Chaque point porte son motif : sans lui, l'utilisateur
                        // n'a aucun moyen de juger de ce qu'il risque a le negliger.
                        //
                        // `fait` valait `null` sur cinq points des six : la liste
                        // affichait cinq pastilles numerotees, indiscernables
                        // d'un travail non fait, alors que rien n'avait ete
                        // verifie. Ce qui se verifie porte maintenant sa coche ;
                        // ce qui ne se verifie pas d'ici le dit, plutot que de
                        // laisser croire a un manquement.
                        $sitesNommes = $entreprise->pointsDeVente()->count();
                        $clientsAvecNcc = \App\Modules\Admin\Modeles\Client::where('entreprise_id', $entreprise->id)
                            ->whereNotNull('ncc')->where('ncc', '!=', '')->count();
                        $tauxHorsBareme = \App\Modules\Admin\Modeles\Produit::where('entreprise_id', $entreprise->id)
                            ->selectionnables()
                            ->whereNotNull('taux_tva')
                            ->whereNotIn('taux_tva', \App\Modules\Admin\Modeles\Produit::TAUX_TVA_DGI)
                            ->count();

                        $etapesConformite = [
                            [
                                'titre' => 'Renseigner l\'identité fiscale complète',
                                'texte' => 'NCC, régime d\'imposition, RCCM et centre des impôts. Le NCC identifie votre entreprise auprès de la plateforme : sans lui, aucune facture n\'est certifiée.',
                                'fait' => !empty($entreprise->ncc) && !empty($entreprise->regime_imposition) && !empty($entreprise->rccm),
                                'constat' => null,
                            ],
                            [
                                'titre' => 'Nommer les points de vente à l\'identique',
                                'texte' => 'Le nom saisi dans Selflow est transmis tel quel à la plateforme, qui refuse la facture s\'il ne correspond à aucun point de vente déclaré sur votre espace.',
                                // On sait compter les sites ; on ne peut pas lire
                                // les noms declares chez la DGI — l'API ne les
                                // expose pas. Le point reste donc a verifier a la
                                // main, et le dire vaut mieux que le taire.
                                'fait' => null,
                                'constat' => $sitesNommes === 1
                                    ? 'Un point de vente déclaré ici. Vérifiez qu\'il porte le même nom sur votre espace FNE.'
                                    : $sitesNommes . ' points de vente déclarés ici. Vérifiez que chacun porte le même nom sur votre espace FNE.',
                            ],
                            [
                                'titre' => 'N\'utiliser que les taux de TVA du barème DGI',
                                'texte' => '18 %, 9 % ou 0 %. La plateforme ne reçoit pas un pourcentage mais un code, et applique le taux attaché à ce code : un taux intermédiaire — 5 % par exemple — serait taxé à 18 % sur la facture certifiée.',
                                'fait' => $tauxHorsBareme === 0,
                                'constat' => $tauxHorsBareme === 0
                                    ? 'Aucun article hors barème dans votre catalogue.'
                                    : $tauxHorsBareme . ' article(s) portent un taux que la plateforme ne sait pas représenter.',
                            ],
                            [
                                'titre' => 'Saisir le NCC des clients professionnels',
                                'texte' => 'Une facture B2B sans NCC client est rejetée par la plateforme. Sans NCC, la vente relève du B2C — ce qui est juste pour un particulier, et faux pour une entreprise.',
                                // Aucun moyen de savoir lesquels de vos clients
                                // sont des professionnels : la fiche ne porte pas
                                // la distinction. On compte ce qu'on sait compter.
                                'fait' => null,
                                'constat' => $clientsAvecNcc . ' de vos clients portent un NCC.',
                            ],
                            [
                                'titre' => 'Signaler un écart avec les options de votre espace FNE',
                                'texte' => 'Le timbre de quittance et le bordereau d\'achat agricole se règlent sur la plateforme, et l\'API ne permet pas de les lire. L\'état affiché plus bas est celui que votre administrateur Selflow y a constaté : s\'il ne correspond plus, signalez-le — vous encaisseriez un timbre que la plateforme ne retiendra pas.',
                                'fait' => null,
                                'constat' => 'Timbre de quittance : ' . ($entreprise->timbre_quittance ? 'appliqué' : 'non appliqué')
                                    . ' · Normalisation : ' . (($entreprise->normalisation_auto_factures ?? true) ? 'automatique' : 'manuelle') . '.',
                            ],
                            [
                                'titre' => 'Surveiller le solde de stickers',
                                'texte' => 'La certification consomme un sticker par pièce. À zéro, plus rien n\'est normalisé. Le solde figure sur la page Gestion FNE, tel que la plateforme l\'a renvoyé à la dernière certification.',
                                'fait' => null,
                                'constat' => 'Vous serez alerté à ' . (int) ($entreprise->sticker_solde_alerte ?? 5) . ' sticker(s) restants.',
                            ],
                        ];
                    @endphp

                    {{-- Sur une demi-largeur, les six points formaient une
                         colonne de texte longue de deux écrans, et la moitié
                         droite de la page restait vide. La carte prend toute
                         la largeur : les points tiennent sur deux colonnes et
                         le barème du timbre occupe la place laissée libre. --}}
                    <div class="conformite-corps">
                        <ol class="conformite-etapes">
                        @foreach($etapesConformite as $etape)
                            @php
                                // Trois états, et non deux : vérifié, à corriger,
                                // et « nous ne pouvons pas le vérifier d'ici ».
                                // Confondre les deux derniers sous une même
                                // pastille grise faisait passer six points pour
                                // autant de travaux en retard.
                                $couleurs = match ($etape['fait']) {
                                    true  => ['fond' => '#d1fae5', 'bord' => '#6ee7b7', 'encre' => '#047857'],
                                    false => ['fond' => '#fef3c7', 'bord' => '#fcd34d', 'encre' => '#b45309'],
                                    default => ['fond' => 'var(--bg3)', 'bord' => 'var(--border)', 'encre' => 'var(--text-3)'],
                                };
                            @endphp
                            <li style="display:flex;gap:12px;align-items:flex-start;">
                                <span
                                    style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:{{ $couleurs['fond'] }};border:1px solid {{ $couleurs['bord'] }};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:{{ $couleurs['encre'] }};margin-top:1px;">
                                    @if($etape['fait'] === true)
                                        <i class="fas fa-check" style="font-size:9px;"></i>
                                    @elseif($etape['fait'] === false)
                                        <i class="fas fa-exclamation" style="font-size:9px;"></i>
                                    @else
                                        {{ $loop->iteration }}
                                    @endif
                                </span>
                                <div>
                                    <div style="font-weight:600;font-size:13px;color:var(--text);">
                                        {{ $etape['titre'] }}
                                        @if($etape['fait'] === null)
                                            <span style="font-size:10px;font-weight:600;color:var(--text-3);text-transform:none;letter-spacing:0;">
                                                — à vérifier sur votre espace FNE
                                            </span>
                                        @endif
                                    </div>
                                    <div style="font-size:12px;color:var(--text-3);line-height:1.6;margin-top:2px;">
                                        {{ $etape['texte'] }}</div>
                                    @if($etape['constat'])
                                        <div style="font-size:11.5px;color:{{ $couleurs['encre'] }};line-height:1.5;margin-top:4px;font-weight:600;">
                                            {{ $etape['constat'] }}
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <div
                        style="padding:12px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:12px;color:#92400e;line-height:1.6;">
                        <strong><i class="fas fa-scale-balanced"></i> Droit de timbre de quittance</strong> —
                        barème de l'article 873 du Code général des impôts, appliqué aux règlements
                        en espèces. Il est dû par le client (article 875) : vous le collectez pour l'État.
                        <div
                            style="margin-top:8px;display:grid;grid-template-columns:1fr auto;gap:2px 16px;font-family:ui-monospace,monospace;font-size:11px;">
                            <span>0 – 5 000</span><span style="text-align:right;">0 F</span>
                            <span>5 001 – 100 000</span><span style="text-align:right;">100 F</span>
                            <span>100 001 – 500 000</span><span style="text-align:right;">500 F</span>
                            <span>500 001 – 1 000 000</span><span style="text-align:right;">1 000 F</span>
                            <span>1 000 001 – 5 000 000</span><span style="text-align:right;">2 000 F</span>
                            <span>au-delà de 5 000 000</span><span style="text-align:right;">5 000 F</span>
                        </div>
                        <div style="margin-top:8px;">
                            Ni les avoirs ni les bordereaux d'achat n'en relèvent : le timbre frappe
                            la quittance, l'acte qui constate un encaissement.
                        </div>
                    </div>
                    </div>{{-- /corps sur deux colonnes --}}
                </div>
        </div>{{-- /grille --}}

        <div style="display:flex;justify-content:flex-end;margin-top:20px;gap:10px;">
            <a href="{{ route('admin.tableau_de_bord') }}" class="btn btn-outline">Annuler</a>
            <button type="submit" class="btn btn-primary" style="padding:12px 28px;">
                <i class="fas fa-save"></i> Enregistrer les paramètres
            </button>
        </div>
    </form>

    <div class="card" style="margin-top:24px; padding:24px;">
        <div
            style="font-size:14px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <span id="exercices" style="scroll-margin-top:90px;"></span><i class="far fa-calendar-alt" style="color:var(--primary); font-size:16px;"></i> Gestion des Exercices
            Comptables (Périodes)
        </div>

        <div style="display:grid; grid-template-columns: 1fr 2fr; gap:32px; align-items:start;">
            {{-- Formulaire de création d'un exercice --}}
            <div style="background:var(--bg3); border-radius:10px; padding:20px; border:1px solid var(--border);">
                <h3 style="font-size:13px; font-weight:700; margin-bottom:14px; color:var(--primary);"><i
                        class="fas fa-plus"></i> Nouvel Exercice</h3>

                <form method="POST" action="{{ route('admin.entreprise.periodes.creer') }}">
                    @csrf
                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">Date de début <span style="color:var(--danger)">*</span></label>
                        <input type="date" name="date_debut" class="form-control" required value="{{ date('Y-01-01') }}">
                    </div>

                    <div class="form-group" style="margin-bottom:14px;">
                        <label class="form-label">Date de fin <span style="color:var(--danger)">*</span></label>
                        <input type="date" name="date_fin" class="form-control" required value="{{ date('Y-12-31') }}">
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm" style="width:100%; justify-content:center;">
                        <i class="fas fa-circle-check"></i> Créer l'exercice
                    </button>
                </form>
            </div>

            {{-- Tableau des exercices existants --}}
            <div>
                <h3 style="font-size:13px; font-weight:700; margin-bottom:14px; color:var(--text-2);"><i
                        class="fas fa-list-ul"></i> Exercices enregistrés</h3>
                <div class="table-wrap" style="border:1px solid var(--border);">
                    <table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Période</th>
                                <th style="text-align: center;">Statut</th>
                                <th style="text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($periodes as $p)
                                <tr>
                                    <td><strong>{{ $p->nom }}</strong></td>
                                    <td>
                                        Du {{ \Carbon\Carbon::parse($p->date_debut)->format('d/m/Y') }}
                                        au {{ \Carbon\Carbon::parse($p->date_fin)->format('d/m/Y') }}
                                    </td>
                                    <td style="text-align: center;">
                                        @if($p->estCloture())
                                            <span class="badge badge-danger"
                                                style="background:#fef2f2; color:#991b1b; border:1px solid #fca5a5;"><i
                                                    class="fas fa-lock"></i> Clôturé</span>
                                        @elseif(session('active_periode_id') == $p->id)
                                            <span class="badge badge-success"><i class="fas fa-circle-check"></i> Sélectionné</span>
                                        @elseif($p->est_active)
                                            <span class="badge badge-info">Actif</span>
                                        @else
                                            <span class="badge badge-gray">Inactif</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center;">
                                        @if($p->estCloture())
                                            <span style="font-size:11px; font-weight:600; color:var(--text-3);">Aucune action</span>
                                        @elseif(session('active_periode_id') == $p->id)
                                            <span style="font-size:11px; font-weight:600; color:var(--success);">Actif en
                                                session</span>
                                        @else
                                            <div style="display:flex; gap:6px; justify-content:center; align-items:center;">
                                                <form method="POST" action="{{ route('admin.periods.switch') }}" style="margin:0;">
                                                    @csrf
                                                    <input type="hidden" name="periode_id" value="{{ $p->id }}">
                                                    <button type="submit" class="btn btn-outline btn-sm"
                                                        style="padding: 4px 8px; font-size:11px;">
                                                        <i class="fas fa-right-from-bracket"></i> Basculer
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.entreprise.periodes.cloturer', $p) }}"
                                                    style="margin:0;"
                                                    onsubmit="return confirm('Êtes-vous sûr de vouloir clôturer DEFINITIVEMENT cet exercice ? Toutes les écritures de cette période seront verrouillées et non modifiables.')">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm"
                                                        style="padding: 4px 8px; font-size:11px; color:var(--danger); border-color:var(--danger);">
                                                        <i class="fas fa-lock"></i> Clôturer
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--text-3); padding: 20px 0;">
                                        Aucun exercice enregistré.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── LIAISON COMPTAFLOW ──────────────────────────────────────────────

         Cette carte portait un bouton « Lancer la synchronisation test » qui
         annonçait « Synchronisation bidirectionnelle réussie ! Les écritures
         comptables et les statuts des factures ont été synchronisés » sans
         qu'aucun appel ne parte. Elle affichait par ailleurs un statut
         comparé à « Actif » quand le reste du code écrit « active » : le
         voyant restait rouge sur une liaison qui marchait.

         Et le champ de clé, plus haut dans la page, laissait coller la clé
         d'une autre entreprise — la liaison s'ouvrait alors vers ses livres.
         Personne ne saisit plus de clé : on demande, le superadministrateur
         valide, Comptaflow la délivre. --}}
    <div class="card" style="margin-top:24px; padding:24px;">
        <div
            style="font-size:14px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <span id="comptaflow-liaison" style="scroll-margin-top:90px;"></span><i class="fas fa-link" style="color:var(--primary); font-size:16px;"></i> Comptabilité — liaison Comptaflow
        </div>

        @php
            $liee     = $entreprise->liaisonComptaflowActive();
            $enAttente = $entreprise->demandeComptaflowEnAttente();
            $refusee  = $entreprise->comptaflow_demande_statut === \App\Modules\Admin\Modeles\Entreprise::DEMANDE_REFUSEE;
        @endphp

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:28px; align-items:start;" class="conformite-corps">
            <div>
                <div style="font-size:13px; color:var(--text-2); margin-bottom:16px; line-height:1.6;">
                    Comptaflow est la solution comptable reliée à Selflow. Une fois la liaison
                    ouverte, chaque écriture produite ici — vente, achat, règlement, mouvement de
                    stock — part vers votre dossier comptable au fil de l'eau, avec votre plan
                    comptable, vos journaux et vos tiers.
                    <br><br>
                    <strong>Vous n'avez aucune clé à saisir ni à aller chercher.</strong> Elle est
                    délivrée à votre dossier quand votre demande est validée.
                </div>

                <div style="display:flex; flex-direction:column; gap:10px; font-size:13px;">
                    <div>
                        État :
                        @if($liee)
                            <span class="badge badge-success">Liaison active</span>
                        @elseif($enAttente)
                            <span class="badge badge-warning">Demande en attente de validation</span>
                        @elseif($refusee)
                            <span class="badge badge-danger">Demande refusée</span>
                        @else
                            <span class="badge badge-danger">Aucune liaison</span>
                        @endif
                    </div>
                    @if($liee)
                        <div>Dossier Comptaflow : <strong>#{{ $entreprise->comptaflow_company_id ?? '—' }}</strong></div>
                        <div>Liaison ouverte le :
                            <strong>{{ $entreprise->comptaflow_liee_le?->format('d/m/Y à H:i') ?? '—' }}</strong>
                        </div>
                        <div>Dernier déversement :
                            <strong>{{ $entreprise->comptaflow_last_sync_at?->format('d/m/Y à H:i') ?? 'aucun' }}</strong>
                        </div>
                        {{-- Quatre caractères, et rien de plus : de quoi
                             reconnaître la clé, pas de quoi s'en servir. --}}
                        <div style="color:var(--text-3);">Clé du dossier :
                            <code>{{ $entreprise->indiceCleComptaflow() ?? '••••' }}</code>
                        </div>
                    @elseif($enAttente)
                        <div>Demandée le :
                            <strong>{{ $entreprise->comptaflow_demande_le?->format('d/m/Y à H:i') ?? '—' }}</strong>
                        </div>
                    @endif
                </div>
            </div>

            <div style="background:var(--bg3); border-radius:10px; padding:22px; border:1px solid var(--border);">
                @if($liee)
                    <div style="font-size:13px;color:#065f46;line-height:1.7;">
                        <i class="fas fa-circle-check" style="color:#10b981;"></i>
                        <strong>Tout est en place.</strong> Vos écritures partent d'elles-mêmes.
                    </div>

                    {{-- L'entreprise apprenait qu'elle avait un dossier comptable
                         sans savoir où le consulter, ni avec quels identifiants.
                         Les deux manquaient : l'adresse n'était écrite nulle
                         part, et rien ne disait que le compte est le même. --}}
                    <div style="margin-top:14px;padding:12px 14px;background:#fff;border:1px solid #d1fae5;border-radius:8px;font-size:12.5px;line-height:1.7;">
                        <strong>Vos accès sont les mêmes qu'ici.</strong><br>
                        Identifiant : <strong>{{ Auth::user()->email }}</strong><br>
                        Mot de passe : celui de votre compte Selflow.
                        <div style="margin-top:10px;">
                            <a href="{{ rtrim(config('selflow.comptaflow_app_url'), '/') }}" target="_blank" rel="noopener"
                               class="btn btn-primary btn-sm" style="padding:7px 14px;">
                                <i class="fas fa-arrow-up-right-from-square"></i> Ouvrir ma comptabilité
                            </a>
                        </div>
                    </div>

                    {{-- Lancer le déversement de tout ce que Selflow détient.
                         Rien ne le permettait depuis un écran : une entreprise
                         reliée après six mois d'activité gardait ses six mois,
                         et seules les écritures créées ensuite partaient. --}}
                    @php $resteADeverser = \App\Modules\Admin\Services\DeversementHistoriqueService::reste($entreprise); @endphp
                    <div style="margin-top:14px;padding:12px 14px;background:#fff;border:1px solid var(--border);border-radius:8px;font-size:12.5px;line-height:1.7;">
                        <strong>Déverser tout l'historique</strong><br>
                        @if($resteADeverser['operations'] > 0)
                            <span style="color:#c2410c;">
                                {{ $resteADeverser['operations'] }} opération(s) ne sont pas encore chez Comptaflow@if($resteADeverser['en_echec'] > 0), dont {{ $resteADeverser['en_echec'] }} en échec@endif.
                            </span>
                        @else
                            <span style="color:var(--text-3);">Tout est déversé.</span>
                        @endif
                        <div style="margin-top:10px;">
                            <button type="button" id="btn-deverser-tout" onclick="lancerLeDeversementComptaflow()"
                                    class="btn btn-outline btn-sm" style="padding:7px 14px;">
                                <i class="fas fa-cloud-arrow-up"></i> Lancer le déversement
                            </button>
                        </div>
                        <div id="retour-deversement" style="display:none;margin-top:10px;padding:10px 12px;border-radius:6px;font-size:12px;line-height:1.5;"></div>
                    </div>

                    <div style="margin-top:12px;font-size:11.5px;color:var(--text-3);line-height:1.6;">
                        Pour délier ce dossier, écrivez au support : la clé doit être révoquée
                        des deux côtés le même jour.
                    </div>
                @elseif($enAttente)
                    <div style="font-size:13px;color:#92400e;line-height:1.7;">
                        <i class="fas fa-hourglass-half"></i>
                        <strong>Votre demande est enregistrée.</strong> Elle est vérifiée avant
                        qu'un livre s'ouvre à votre nom : identité fiscale, NCC, RCCM. Vous
                        n'avez rien d'autre à faire — la liaison se fera seule.
                    </div>
                @else
                    @if($refusee && $entreprise->comptaflow_refus_motif)
                        <div style="font-size:12.5px;color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 12px;margin-bottom:14px;line-height:1.6;">
                            <strong>Demande précédente refusée :</strong>
                            {{ $entreprise->comptaflow_refus_motif }}
                        </div>
                    @endif
                    <p style="font-size:13px;color:var(--text-2);line-height:1.6;margin-bottom:16px;">
                        Demandez l'ouverture d'un dossier comptable. Les informations déjà
                        renseignées sur cette page suffisent ; rien ne vous sera redemandé.
                    </p>
                    <form method="POST" action="{{ route('admin.entreprise.comptaflow.demander') }}" style="margin:0;">
                        @csrf
                        <button type="submit" class="btn btn-primary" style="padding:10px 22px;font-weight:700;gap:8px;">
                            <i class="fas fa-link"></i> Demander la liaison Comptaflow
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- ── STATUT FNE (DGI) — Lecture seule, la clé n'est jamais affichée ici ── --}}
    <div class="card" style="margin-top:24px; padding:24px;">
        <div
            style="font-size:14px;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:20px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <span id="statut-fne" style="scroll-margin-top:90px;"></span><i class="fas fa-key" style="color:var(--primary); font-size:16px;"></i> FNE — Facture Normalisée Électronique
            (DGI)
        </div>

        <div style="display:grid; grid-template-columns: 1fr 2fr; gap:32px; align-items:center;">
            <div>
                <div style="font-size:13px; color:var(--text-2); margin-bottom:14px; line-height:1.5;">
                    La normalisation électronique des factures auprès de la DGI nécessite une clé propre à votre entreprise.
                    Pour des raisons de sécurité, cette clé n'est ni affichée ni modifiable ici — seul le support Selflow
                    peut la configurer, sur la base de la clé que vous recevez de la DGI.
                </div>

                <div style="display:flex; flex-direction:column; gap:10px; font-size:13px;">
                    <div>
                        Statut :
                        @php
                            $fneBadgeClasse = match ($fneStatut['statut']) {
                                'validee' => 'badge-success',
                                'test' => 'badge-warning',
                                default => 'badge-danger',
                            };
                        @endphp
                        <span id="fne-status-badge" class="badge {{ $fneBadgeClasse }}">{{ $fneStatut['label'] }}</span>
                    </div>
                    <div>
                        Dernière vérification :
                        <strong
                            id="fne-last-check">{{ $fneStatut['derniere_verification'] ? \Carbon\Carbon::parse($fneStatut['derniere_verification'])->format('d/m/Y \à H:i:s') : 'Jamais vérifié' }}</strong>
                    </div>
                    @if($fneStatut['statut'] === 'non_configure')
                        <div
                            style="color:#92400e; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 12px; margin-top:4px;">
                            <i class="fas fa-circle-info"></i> Aucune clé FNE n'est encore configurée. Contactez le support
                            Selflow pour lancer la demande auprès de la DGI.
                        </div>
                    @elseif($fneStatut['statut'] === 'test')
                        <div
                            style="color:#92400e; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 12px; margin-top:4px;">
                            <i class="fas fa-flask"></i> Vous êtes en phase de test DGI. Les factures normalisées ne sont pas
                            encore fiscalement définitives.
                        </div>
                    @endif
                </div>
            </div>

            <div
                style="background:var(--bg3); border-radius:10px; padding:24px; border:1px solid var(--border); text-align:center;">
                <p style="font-size:13px; font-weight:600; margin-bottom:16px; color:var(--text-1);">Vérifier la
                    joignabilité du serveur DGI</p>

                <div id="fne-feedback"
                    style="display:none; padding:12px; border-radius:8px; font-size:13px; margin-bottom:16px; text-align:left; font-weight:500;">
                </div>

                <button type="button" id="btn-fne-test" onclick="testerConnexionFne()" class="btn btn-primary"
                    style="margin:0 auto; padding:10px 24px; font-weight:700; gap:8px;" {{ $fneStatut['statut'] === 'non_configure' ? 'disabled' : '' }}>
                    <i class="fas fa-satellite-dish"></i> Tester la connexion
                </button>
                <span id="fne-loader" style="display:none; font-size:13px; color:var(--text-3); font-weight:600;">
                    <i class="fas fa-spinner fa-spin" style="color:var(--primary); margin-right:8px;"></i> Test en cours...
                </span>
            </div>
        </div>
    </div>

    <script>
        function testerConnexionFne() {
            const btn = document.getElementById('btn-fne-test');
            const loader = document.getElementById('fne-loader');
            const feedback = document.getElementById('fne-feedback');
            const lastCheck = document.getElementById('fne-last-check');

            btn.style.display = 'none';
            loader.style.display = 'inline-flex';
            feedback.style.display = 'none';

            fetch("{{ route('admin.entreprise.fne.tester_connexion') }}", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "{{ csrf_token() }}" }
            })
                .then(response => response.json())
                .then(data => {
                    btn.style.display = 'inline-flex';
                    loader.style.display = 'none';
                    feedback.style.display = 'block';

                    if (data.success) {
                        feedback.style.background = '#d1fae5';
                        feedback.style.border = '1px solid #6ee7b7';
                        feedback.style.color = '#065f46';
                    } else {
                        feedback.style.background = '#fee2e2';
                        feedback.style.border = '1px solid #fca5a5';
                        feedback.style.color = '#991b1b';
                    }
                    feedback.innerHTML = data.message;
                    lastCheck.textContent = new Date().toLocaleString('fr-FR');
                })
                .catch(() => {
                    btn.style.display = 'inline-flex';
                    loader.style.display = 'none';
                    feedback.style.display = 'block';
                    feedback.style.background = '#fee2e2';
                    feedback.style.border = '1px solid #fca5a5';
                    feedback.style.color = '#991b1b';
                    feedback.innerHTML = "Une erreur réseau s'est produite lors du test.";
                });
        }
    </script>
<script>
/**
 * Lancer le deversement de l'historique vers Comptaflow.
 *
 * La confirmation avertit de ce qui compte : **la configuration de Comptaflow
 * s'applique**, et c'est celle qui est en place au moment du deversement. La
 * verifier apres coup ne rattraperait rien -- les numeros seront deja poses.
 */
function lancerLeDeversementComptaflow() {
    var avertissement =
        "Avant de lancer le deversement, verifiez la configuration de votre dossier Comptaflow :\n\n"
        + "  \u2022 le modele de plan comptable ;\n"
        + "  \u2022 le modele de tiers ;\n"
        + "  \u2022 le modele de journaux.\n\n"
        + "C'est cette configuration qui sera utilisee pour numeroter vos comptes, "
        + "vos journaux et vos tiers. La changer apres coup ne renumerotera pas "
        + "ce qui aura deja ete depose.\n\n"
        + "Lancer le deversement maintenant ?";

    if (!window.confirm(avertissement)) return;

    var bouton = document.getElementById('btn-deverser-tout');
    var retour = document.getElementById('retour-deversement');
    bouton.disabled = true;
    bouton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deversement en cours...';

    fetch(@json(route('admin.entreprise.comptaflow.deverser')), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
    })
    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
    .then(function (res) {
        retour.style.display = 'block';
        retour.style.background = res.d.success ? '#f0fdf4' : '#fef2f2';
        retour.style.border = '1px solid ' + (res.d.success ? '#86efac' : '#fca5a5');
        retour.style.color = res.d.success ? '#15803d' : '#991b1b';
        retour.textContent = res.d.message || 'Reponse illisible du serveur.';
    })
    .catch(function () {
        retour.style.display = 'block';
        retour.style.background = '#fef2f2';
        retour.style.border = '1px solid #fca5a5';
        retour.style.color = '#991b1b';
        retour.textContent = 'Le serveur n\'a pas repondu. Reessayez.';
    })
    .finally(function () {
        bouton.disabled = false;
        bouton.innerHTML = '<i class="fas fa-cloud-arrow-up"></i> Lancer le deversement';
    });
}
</script>

@endsection