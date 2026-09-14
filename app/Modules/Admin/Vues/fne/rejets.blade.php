@extends('admin::gabarits.application')
@section('titre', 'Pièces refusées par la DGI')
@section('topbar_titre', 'Fiscalité & DGI — Pièces refusées')

@section('styles')
    <style>
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 26px;
        }

        .kpi-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px 24px;
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .kpi-card.alerte::before {
            background: #E53E3E;
        }

        .kpi-card.ok::before {
            background: #10b981;
        }

        .kpi-card .lbl {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-3);
            letter-spacing: .4px;
            margin-bottom: 8px;
        }

        .kpi-card .val {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-1);
        }

        .kpi-card .sub {
            font-size: 12px;
            color: var(--text-3);
            margin-top: 6px;
        }

        .fne-tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid var(--border);
            margin-bottom: 24px;
        }

        .fne-tab {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-3);
            cursor: pointer;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all .15s;
        }

        .fne-tab:hover {
            color: var(--primary);
        }

        .fne-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .rejet {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .rejet-tete {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: #fbfcfe;
        }

        .rejet-num {
            font-family: monospace;
            font-weight: 800;
            font-size: 15px;
            color: var(--text-1);
        }

        .rejet-corps {
            padding: 18px 20px;
        }

        .etiquette {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .et-ouvert {
            background: #fff5f5;
            color: #c53030;
        }

        .et-diagnostique {
            background: #fffbeb;
            color: #92400e;
        }

        .et-resolu {
            background: #ecfdf5;
            color: #065f46;
        }

        /* La cause : pourquoi la pièce n'est pas passée. Volontairement plus
           discrète que le statut — le statut dit ce qu'il reste à faire, la cause
           dit seulement où chercher. */
        .cause {
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
            white-space: nowrap;
        }

        .cause-dgi {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .cause-reseau {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .cause-locale {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .cause-vide {
            background: transparent;
            color: var(--text-3);
            border: 1px dashed var(--border);
        }

        .filtres-cause {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .filtre-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            border: 1px solid var(--border);
            border-radius: 20px;
            background: var(--surface, #fff);
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            text-decoration: none;
            transition: all .15s;
        }

        .filtre-pill:hover {
            border-color: #94a3b8;
            color: var(--text-1);
        }

        .filtre-pill.active {
            background: var(--text-1);
            border-color: var(--text-1);
            color: #fff;
        }

        .filtre-pill .n {
            font-size: 11px;
            font-weight: 800;
            padding: 1px 7px;
            border-radius: 20px;
            background: rgba(100, 116, 139, .14);
        }

        .filtre-pill.active .n {
            background: rgba(255, 255, 255, .22);
        }

        .filtre-pill.vide {
            opacity: .45;
        }

        .champ {
            border-left: 3px solid var(--border);
            padding: 2px 0 2px 14px;
            margin-bottom: 14px;
        }

        .champ.ecart {
            border-left-color: #E53E3E;
        }

        .champ.concordant {
            border-left-color: #10b981;
        }

        .champ.hors_portee {
            border-left-color: #cbd5e1;
        }

        .champ.a_verifier,
        .champ.sans_releve {
            border-left-color: #f59e0b;
        }

        .champ-nom {
            font-family: monospace;
            font-weight: 700;
            font-size: 12px;
            color: var(--text-1);
        }

        .champ-txt {
            font-size: 13px;
            color: var(--text-2);
            margin-top: 4px;
            line-height: 1.55;
        }

        .valeurs {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 8px;
            font-size: 12px;
        }

        .valeur {
            padding: 4px 10px;
            border-radius: 8px;
            font-family: monospace;
            font-weight: 700;
        }

        .v-envoye {
            background: #fff5f5;
            color: #c53030;
        }

        .v-portail {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .msg-dgi {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 12px;
            color: var(--text-2);
            line-height: 1.55;
            margin-bottom: 16px;
            word-break: break-word;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .actions form {
            display: inline;
        }

        /* Le panneau de correction : l'action que l'écran met en avant. Un écart de
           nom se répare d'un geste, et ce geste doit se voir — d'où le relief, là où
           le reste de la carte reste sobre. */
        .correction {
            margin-top: 14px;
            padding: 16px 18px;
            border-radius: 14px;
            background: linear-gradient(135deg, #eef2ff, #f5f3ff);
            border: 1px solid #c7d2fe;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .correction-txt {
            flex: 1;
            min-width: 230px;
        }

        .correction-txt .t {
            font-size: 12px;
            font-weight: 800;
            color: #3730a3;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: .3px;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .correction-txt .d {
            font-size: 12.5px;
            color: #4338ca;
            line-height: 1.55;
        }

        .correction-txt .d b {
            font-family: monospace;
            font-weight: 700;
        }

        .btn-correction {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 12px 22px;
            border: none;
            border-radius: 11px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            box-shadow: 0 4px 14px rgba(79, 70, 229, .35);
            transition: transform .12s ease, box-shadow .12s ease, filter .12s ease;
        }

        .btn-correction:hover {
            transform: translateY(-1px);
            box-shadow: 0 7px 22px rgba(79, 70, 229, .45);
            filter: brightness(1.06);
        }

        .btn-correction:active {
            transform: translateY(0);
            box-shadow: 0 3px 10px rgba(79, 70, 229, .35);
        }

        .btn-correction i {
            font-size: 14px;
        }

        .ecarts-fiche {
            border: 1px solid #fde68a;
            background: #fffbeb;
            border-radius: 14px;
            padding: 18px 20px;
            margin-top: 28px;
        }

        .ecarts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .ecarts-table th {
            text-align: left;
            padding: 8px 12px;
            font-size: 11px;
            text-transform: uppercase;
            color: #92400e;
            border-bottom: 1px solid #fde68a;
        }

        .ecarts-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #fef3c7;
            font-size: 13px;
        }

        .ecarts-table tr:last-child td {
            border-bottom: none;
        }

        @media (max-width:860px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endsection

@section('contenu')

    {{-- Les messages de succès et d'erreur s'affichent en toast (pop-up), rendu
    par la gabarit. Plus de bandeau ici : il ferait doublon. --}}

    {{-- Onglets FNE --}}
    <div class="fne-tabs">
        <a href="{{ route('admin.fne.gestion') }}" class="fne-tab"><i class="fas fa-chart-bar"></i> Gestion</a>
        <a href="{{ route('admin.fne.situation') }}" class="fne-tab"><i class="fas fa-balance-scale"></i> Situation</a>
        <a href="{{ route('admin.fne.factures') }}" class="fne-tab"><i class="fas fa-file-invoice"></i> Factures</a>
        @if(in_array('achats', (array) ($entreprise->modules_actifs ?? [])))
            <a href="{{ route('admin.achats.factures_recues') }}" class="fne-tab"><i class="fas fa-inbox"></i> Factures
                reçues</a>
        @endif
        <a href="{{ route('admin.fne.stickers') }}" class="fne-tab"><i class="fas fa-stamp"></i> Stickers</a>
        <a href="{{ route('admin.fne.rejets') }}" class="fne-tab active"><i class="fas fa-triangle-exclamation"></i> Rejets
            @if($kpis['ouverts'] > 0)
                <span
                    style="background:#E53E3E;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;margin-left:4px;">{{ $kpis['ouverts'] }}</span>
            @endif
        </a>
    </div>

    {{-- KPIs --}}
    <div class="kpi-grid">
        <div class="kpi-card {{ $kpis['ouverts'] > 0 ? 'alerte' : 'ok' }}">
            <div class="lbl"><i class="fas fa-circle-exclamation"></i> À traiter</div>
            <div class="val" style="{{ $kpis['ouverts'] > 0 ? 'color:#E53E3E;' : '' }}">{{ $kpis['ouverts'] }}</div>
            <div class="sub">pièce(s) refusée(s), pas encore rapprochée(s)</div>
        </div>
        <div class="kpi-card">
            <div class="lbl"><i class="fas fa-magnifying-glass"></i> Rapprochées</div>
            <div class="val">{{ $kpis['diagnostiques'] }}</div>
            <div class="sub">comparées au relevé du portail</div>
        </div>
        <div class="kpi-card ok">
            <div class="lbl"><i class="fas fa-check"></i> Classées</div>
            <div class="val">{{ $kpis['resolus'] }}</div>
            <div class="sub">la pièce est passée depuis</div>
        </div>
    </div>

    {{-- Filtre par cause.

    Un rejet réseau et un refus de la DGI se ressemblaient à l'écran : même
    étiquette « À traiter », même bouton « Rapprocher ». Or l'un se répare en
    renvoyant la pièce, l'autre en corrigeant une donnée déclarée au portail.
    Les mélanger, c'est chercher un écart de paramétrage là où il n'y a eu
    qu'une coupure. --}}
    <div class="filtres-cause">
        <a href="{{ route('admin.fne.rejets') }}" class="filtre-pill {{ $causeActive === null ? 'active' : '' }}">
            Toutes <span class="n">{{ $totalRejets }}</span>
        </a>
        @foreach($filtresCause as $cle => $filtre)
            {{-- Une cause sans aucun rejet reste cliquable mais s'efface : sa
            présence dit qu'elle existe, son effacement qu'elle est vide. --}}
            <a href="{{ route('admin.fne.rejets', ['cause' => $cle]) }}"
                class="filtre-pill {{ $causeActive === $cle ? 'active' : '' }} {{ $filtre['total'] === 0 ? 'vide' : '' }}">
                {{ $filtre['libelle'] }} <span class="n">{{ $filtre['total'] }}</span>
            </a>
        @endforeach
    </div>

    @if($causeActive === 'reseau' && $filtresCause['reseau']['total'] > 0)
        <div
            style="display:flex;gap:12px;padding:14px 18px;background:#f8fafc;border:1px solid var(--border);border-radius:10px;margin-bottom:20px;color:var(--text-2);font-size:13px;line-height:1.6;">
            <i class="fas fa-plug" style="font-size:16px;margin-top:2px;color:#64748b;"></i>
            <div>
                <strong style="color:var(--text-1);">Ces pièces n'ont pas été refusées : la plateforme n'a pas répondu.</strong>
                Elles sont parties trois fois, à trente secondes d'intervalle, sans obtenir de verdict —
                réseau coupé, délai dépassé, ou panne du côté de la DGI. Il n'y a rien à rapprocher du
                portail : <strong>la pièce est simplement à renvoyer</strong> depuis l'écran des factures.
            </div>
        </div>
    @endif

    @if($causeActive === 'locale' && $filtresCause['locale']['total'] > 0)
        <div
            style="display:flex;gap:12px;padding:14px 18px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin-bottom:20px;color:#1e40af;font-size:13px;line-height:1.6;">
            <i class="fas fa-screwdriver-wrench" style="font-size:16px;margin-top:2px;"></i>
            <div>
                <strong>Ces pièces ne sont jamais parties : Selflow a refusé de les envoyer.</strong>
                Clé API absente, avoir sans facture d'origine, ou taux de TVA qu'aucun code FNE ne
                représente. Le défaut est ici, pas au portail — et c'est voulu : transmise telle quelle,
                une ligne à 5 % serait taxée à 18 % par la plateforme, et la facture certifiée afficherait
                un montant différent du vôtre.
            </div>
        </div>
    @endif

    {{-- État du relevé --}}
    <div
        style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text-3);margin-bottom:20px;">
        <i class="fas fa-cloud-arrow-down"></i>
        @if($fiche)
            {{-- Deux dates, et non une : le passage dit que le scraper fonctionne,
            le changement dit ce que le portail a fait. Un relevé identique au
            précédent n'écrit plus de fiche, donc la seconde peut être bien
            plus ancienne que la première sans que rien n'aille mal. --}}
            Dernier relevé du portail :
            <strong
                style="color:var(--text-1);">{{ $dernierPassage?->format('d/m/Y') ?? $fiche->date_scraping?->format('d/m/Y') ?? '—' }}</strong>
            @if($dernierPassage && $fiche->date_scraping && $fiche->date_scraping->lt($dernierPassage))
                <span style="color:var(--text-3);">— inchangé depuis le {{ $fiche->date_scraping->format('d/m/Y') }}</span>
            @endif
        @else
            <span style="color:#92400e;">Aucun relevé du portail pour cette entreprise — le rapprochement ne peut rien
                comparer.</span>
        @endif
        @if($demandes->isNotEmpty())
            <span style="padding:2px 9px;border-radius:20px;background:#fffbeb;color:#92400e;font-weight:700;">
                {{ $demandes->count() }} relèvement(s) en attente
            </span>
        @endif
    </div>

    {{-- Une demande ouverte est un signal voulu : c'est ainsi qu'on voit qu'un
    scraper ne répond plus. Encore faut-il que quelqu'un le voie — sans l'âge,
    une demande de mars ressemble à une demande de ce matin. --}}
    @php $enRetard = $demandes->filter(fn($d) => $d->estEnRetard()); @endphp
    @if($enRetard->isNotEmpty())
        <div
            style="display:flex;gap:12px;padding:14px 18px;background:#fff5f5;border:1px solid #fed7d7;border-radius:10px;margin-bottom:20px;color:#c53030;">
            <i class="fas fa-plug-circle-exclamation" style="font-size:18px;margin-top:2px;"></i>
            <div style="line-height:1.6;">
                <strong>{{ $enRetard->count() }} relevé(s) demandé(s) sans réponse.</strong>
                @foreach($enRetard as $demande)
                    <div style="font-size:12px;">
                        <span style="font-family:monospace;font-weight:700;">{{ $demande->login }}</span>
                        — attend depuis <strong>{{ $demande->attenteLisible() }}</strong>
                        @if($demande->motif) <span style="opacity:.75;">({{ $demande->motif }})</span> @endif
                    </div>
                @endforeach
                <div style="font-size:12px;margin-top:6px;">
                    Trois causes possibles, et aucune ne se corrige toute seule : le relevé du
                    portail n'est pas lancé, il dépose ses fichiers ailleurs que dans le
                    dossier d'import, ou le NCC de l'entreprise ne correspond pas au login du
                    portail.
                </div>
            </div>
        </div>
    @endif

    {{-- Liste des rejets --}}
    @forelse($rejets as $rejet)
        <div class="rejet">
            <div class="rejet-tete">
                <span class="rejet-num">{{ $rejet->numero_piece ?? '#' . $rejet->piece_id }}</span>
                <span class="etiquette et-{{ $rejet->statut }}">
                    {{ ['ouvert' => 'À traiter', 'diagnostique' => 'Rapprochée', 'resolu' => 'Classée'][$rejet->statut] ?? $rejet->statut }}
                </span>
                @php
                    $causesLisibles = [
                        'dgi' => ['Refus DGI', 'cause-dgi'],
                        'reseau' => ['Réseau', 'cause-reseau'],
                        'locale' => ['Bloqué ici', 'cause-locale'],
                    ];
                    [$causeTexte, $causeClasse] = $causesLisibles[$rejet->cause] ?? ['Cause inconnue', 'cause-vide'];
                @endphp
                <span class="cause {{ $causeClasse }}" title="{{ [
                'dgi' => 'La plateforme a examiné la pièce et l\'a refusée. Le relevé du portail sert à comprendre pourquoi.',
                'reseau' => 'La plateforme n\'a pas répondu : la pièce n\'a jamais été examinée. Rien à rapprocher, la pièce est à renvoyer.',
                'locale' => 'Selflow a refusé d\'envoyer la pièce. Le défaut est ici, pas au portail.',
            ][$rejet->cause] ?? 'Rejet consigné avant que la cause ne soit distinguée.' }}">
                    {{ $causeTexte }}
                </span>
                <span style="font-size:12px;color:var(--text-3);">
                    {{ $rejet->piece_type === 'achat' ? 'Bordereau d\'achat' : 'Facture' }}
                    · refusée le {{ $rejet->created_at?->format('d/m/Y à H:i') }}
                </span>
                <div style="margin-left:auto;" class="actions">
                    {{-- Pas de rapprochement quand la DGI n'a rien examiné : il n'y a
                    aucun verdict à comparer au portail. Le bouton est retiré
                    plutôt que grisé — un bouton grisé se clique quand même, et
                    laisse croire qu'il manque un droit. --}}
                    @if($rejet->cause !== 'reseau')
                        <form method="POST" action="{{ route('admin.fne.rejets.diagnostiquer', $rejet) }}">
                            @csrf
                            <button type="submit" class="btn" style="font-size:12px;">
                                <i class="fas fa-rotate"></i> Rapprocher
                            </button>
                        </form>
                    @endif
                    @if($rejet->statut !== 'resolu')
                        <form method="POST" action="{{ route('admin.fne.rejets.resoudre', $rejet) }}">
                            @csrf
                            <button type="submit" class="btn" style="font-size:12px;">
                                <i class="fas fa-check"></i> Classer
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="rejet-corps">
                {{-- Le message de la plateforme, mot pour mot --}}
                <div class="msg-dgi">
                    <strong style="color:var(--text-1);">Réponse de la plateforme :</strong><br>
                    {{ $rejet->message ?? 'Aucun message conservé.' }}
                </div>

                @if($rejet->diagnostic)
                    @foreach($rejet->diagnostic['champs'] ?? [] as $champ)
                        <div class="champ {{ $champ['verdict'] ?? '' }}">
                            <div class="champ-nom">{{ $champ['champ'] }}</div>
                            <div class="champ-txt">{{ $champ['explication'] ?? '' }}</div>

                            @if(!empty($champ['envoye']) || !empty($champ['portail']))
                                <div class="valeurs">
                                    @if(!empty($champ['envoye']))
                                        <span style="color:var(--text-3);">envoyé</span>
                                        <span class="valeur v-envoye">{{ $champ['envoye'] }}</span>
                                    @endif
                                    @foreach($champ['portail'] ?? [] as $declare)
                                        <span style="color:var(--text-3);">portail</span>
                                        <span class="valeur v-portail">{{ $declare }}</span>
                                    @endforeach
                                </div>
                            @endif

                            {{-- La correction ne s'offre que sur un écart de nom, et
                            seulement quand le portail n'en déclare qu'un : la machine
                            ne choisit pas le point de vente à la place de qui a
                            établi la pièce. --}}
                            @if(
                                    ($champ['champ'] ?? null) === 'pointOfSale'
                                    && ($champ['verdict'] ?? null) === 'ecart'
                                    && count($champ['portail'] ?? []) === 1
                                )
                                <div class="correction">
                                    <div class="correction-txt">
                                        <div class="t"><i class="fas fa-wand-magic-sparkles"></i> Correction proposée</div>
                                        <div class="d">
                                            Renommer le point de vente <b>« {{ $champ['envoye'] }} »</b>
                                            en <b>« {{ $champ['portail'][0] }} »</b> — le nom déclaré au portail —
                                            puis renvoyer automatiquement les pièces que ce nom faisait refuser.
                                        </div>
                                    </div>
                                    <form method="POST" action="{{ route('admin.fne.rejets.appliquer', $rejet) }}"
                                        onsubmit="return confirm('Renommer le point de vente « {{ $champ['envoye'] }} » en « {{ $champ['portail'][0] }} » ? Toutes les pièces de ce point de vente porteront ce nom.');">
                                        @csrf
                                        <button type="submit" class="btn-correction">
                                            <i class="fas fa-wand-magic-sparkles"></i>
                                            Lancer la correction
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div
                        style="font-size:12px;color:var(--text-3);border-top:1px dashed var(--border);padding-top:12px;margin-top:4px;">
                        <i class="fas fa-circle-info"></i>
                        {{ $rejet->diagnostic['conclusion'] ?? '' }}
                        @if(!empty($rejet->diagnostic['releve']['date']))
                            <span style="margin-left:4px;">(relevé du {{ $rejet->diagnostic['releve']['date'] }})</span>
                        @endif
                    </div>
                @else
                    <div style="font-size:13px;color:var(--text-3);">
                        <i class="fas fa-hourglass-half"></i>
                        Pas encore rapprochée d'un relevé du portail. Le rapprochement passe chaque heure ;
                        <em>Rapprocher</em> le déclenche tout de suite.
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="card" style="text-align:center;padding:56px;color:var(--text-3);">
            <i class="fas fa-circle-check" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2;"></i>
            @if($causeActive !== null)
                {{-- Sans cette distinction, un filtre sur une cause vide affiche
                « aucune pièce refusée » alors qu'il y en a peut-être vingt sous
                une autre cause. Un écran vide se lit comme une bonne nouvelle. --}}
                Aucun rejet pour cette cause.<br>
                <small>
                    {{ $totalRejets }} rejet(s) au total —
                    <a href="{{ route('admin.fne.rejets') }}" style="text-decoration:underline;">voir toutes les causes</a>.
                </small>
            @else
                Aucune pièce refusée par la plateforme.<br>
                <small>Les refus apparaissent ici dès qu'ils surviennent, avec ce que le portail déclare en face.</small>
            @endif
        </div>
    @endforelse

    @if($rejets->hasPages())
        <div style="padding:16px 0;">{{ $rejets->links() }}</div>
    @endif

    {{-- Écarts de fiche : montrés, jamais appliqués --}}
    @if($ecartsFiche)
        <div class="ecarts-fiche">
            <div style="font-size:14px;font-weight:700;color:#92400e;">
                <i class="fas fa-scale-unbalanced"></i>
                Le portail et votre paramétrage divergent sur {{ count($ecartsFiche) }} point(s)
            </div>
            <p style="font-size:12px;color:#92400e;margin:8px 0 0;line-height:1.6;">
                Ces écarts ne sont la cause d'aucun refus, mais ils méritent d'être tranchés.
                <strong>Selflow ne les applique pas tout seul :</strong> le timbre de quittance,
                le bordereau d'achat agricole et le solde d'alerte commandent ce qui part à la DGI,
                et un relevé ne dit pas qui, du portail ou de votre paramétrage, a raison.
                La correction se fait dans
                <a href="{{ route('admin.entreprise.parametres') }}" style="color:#92400e;text-decoration:underline;">les
                    paramètres de l'entreprise</a>,
                en connaissance de cause.
            </p>

            <table class="ecarts-table">
                <thead>
                    <tr>
                        <th>Champ</th>
                        <th>Au portail</th>
                        <th>Dans Selflow</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ecartsFiche as $nom => $ecart)
                        <tr>
                            <td style="font-family:monospace;font-weight:700;">{{ $nom }}</td>
                            <td style="font-weight:700;color:#1d4ed8;">
                                {{ is_bool($ecart['portail']) ? ($ecart['portail'] ? 'oui' : 'non') : $ecart['portail'] }}
                            </td>
                            <td style="color:var(--text-2);">
                                @if($ecart['selflow'] === null || $ecart['selflow'] === '')
                                    <em style="color:var(--text-3);">vide</em>
                                @else
                                    {{ is_bool($ecart['selflow']) ? ($ecart['selflow'] ? 'oui' : 'non') : $ecart['selflow'] }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection