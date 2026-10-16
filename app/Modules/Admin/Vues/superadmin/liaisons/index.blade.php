@extends('admin::gabarits.application')
@section('titre', 'Liaisons SELFLOW ↔ COMPTAFLOW')
@section('topbar_titre', 'SuperAdmin — Liaisons inter-applications')

@section('styles')
<style>
    .liaison-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 24px;
        margin-bottom: 32px;
    }
    @media (max-width: 900px) { .liaison-grid { grid-template-columns: 1fr; } }

    .stat-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 20px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .stat-icon {
        width: 52px; height: 52px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; flex-shrink: 0;
    }
    .stat-val { font-size: 28px; font-weight: 800; color: var(--text-1); }
    .stat-lbl { font-size: 12px; color: var(--text-3); text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }

    .liaison-badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
    }
    .badge-active   { background: #ecfdf5; color: #065f46; }
    .badge-inactive { background: #f1f5f9; color: #64748b; }
    .badge-error    { background: #fef2f2; color: #991b1b; }
    .badge-attente  { background: #fffbeb; color: #92400e; }

    .direction-arrow {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 11px; color: var(--text-3); font-weight: 600;
        margin-left: 8px;
    }
    .arrow-icon { font-size: 10px; }

    .ent-row td { vertical-align: middle; padding: 12px 14px; }

    .demande-carte {
        border: 1px solid #fcd34d; background: #fffbeb; border-radius: 12px;
        padding: 16px 18px; display: flex; gap: 18px; align-items: flex-start;
        flex-wrap: wrap; margin-bottom: 12px;
    }

    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center; }
    .modal-box     { background:#fff; border-radius:16px; max-width:540px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,.15); overflow:hidden; }
    .modal-header  { padding:18px 22px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
    .modal-body    { padding:22px; }
    .modal-footer  { padding:14px 22px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:10px; }
</style>
@endsection

@section('contenu')

{{-- Alertes --}}
@if(session('success'))
<div style="background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; padding:14px 18px; margin-bottom:20px; color:#065f46; font-weight:600; display:flex; align-items:center; gap:10px;">
    <i class="fas fa-check-circle" style="font-size:18px;"></i> {{ session('success') }}
</div>
@endif
@if(session('error'))
<div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; padding:14px 18px; margin-bottom:20px; color:#991b1b; font-weight:600; display:flex; align-items:center; gap:10px;">
    <i class="fas fa-exclamation-circle" style="font-size:18px;"></i> {{ session('error') }}
</div>
@endif
@error('motif')
<div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; padding:14px 18px; margin-bottom:20px; color:#991b1b; font-weight:600;">
    {{ $message }}
</div>
@enderror

{{-- En-tête --}}
<div class="page-header">
    <div>
        <h1><i class="fas fa-link"></i> Liaisons SELFLOW ↔ COMPTAFLOW</h1>
        <p>
            Une entreprise demande son dossier comptable et vous validez — ou vous le lui ouvrez
            directement. Dans les deux cas, Comptaflow délivre la clé et
            <strong>ses accès Selflow ouvrent son compte comptable</strong> : même adresse,
            même mot de passe.
        </p>
    </div>
    <a href="{{ rtrim(config('selflow.comptaflow_app_url'), '/') }}" target="_blank" rel="noopener"
       class="btn btn-outline">
        <i class="fas fa-arrow-up-right-from-square"></i> Ouvrir Comptaflow
    </a>
</div>

{{-- ══ Ce qui attend une décision ══

     Deux boutons ouvraient ici deux fenêtres qui n'existent plus.

     « Lier manuellement » demandait de coller un identifiant Comptaflow et une
     clé, sans que rien ne vérifie que ce dossier appartenait à cette
     entreprise — et sa route pointait sur une méthode absente du contrôleur,
     si bien qu'elle tombait en 500 (Internal Server Error — erreur du serveur)
     depuis un renommage que personne n'avait remarqué.

     « Créer compte COMPTAFLOW » demandait au superadministrateur de **choisir
     le mot de passe** du compte d'un client, et le transmettait en clair.

     Il n'y a plus qu'un geste : valider, ou refuser en disant pourquoi. --}}
@if($demandes->isNotEmpty())
<div class="card" style="margin-bottom:28px; padding:20px 22px; border-left:4px solid #f59e0b;">
    <h3 style="font-size:15px; font-weight:700; margin:0 0 14px;">
        <i class="fas fa-hourglass-half" style="color:#d97706;"></i>
        {{ $demandes->count() }} demande(s) de dossier comptable en attente
    </h3>

    @foreach($demandes as $dem)
        @php $adminDem = $dem->utilisateurs->first(); @endphp
        <div class="demande-carte">
            <div style="flex:1; min-width:260px;">
                <div style="font-weight:700; font-size:14px; color:var(--text-1);">{{ $dem->nom }}</div>
                <div style="font-size:12px; color:var(--text-2); margin-top:4px; line-height:1.6;">
                    {{ $dem->forme_juridique ?: '— forme juridique non renseignée —' }} ·
                    NCC {{ $dem->ncc ?: '—' }} ·
                    RCCM {{ $dem->rccm ?: '—' }} ·
                    Régime {{ $dem->regime_imposition ?: '—' }}
                    <br>
                    Demandée le {{ $dem->comptaflow_demande_le?->format('d/m/Y à H:i') ?? '—' }}
                    @if($adminDem) · {{ $adminDem->prenom }} {{ $adminDem->nom }} ({{ $adminDem->email }}) @endif
                </div>
                {{-- Un livre va s'ouvrir au nom de quelqu'un : ce qui manque à
                     son identité fiscale se voit avant, pas après. --}}
                @php
                    $manque = collect(['NCC' => $dem->ncc, 'RCCM' => $dem->rccm, 'Régime' => $dem->regime_imposition])
                        ->filter(fn ($v) => blank($v))->keys();
                @endphp
                @if($manque->isNotEmpty())
                    <div style="font-size:12px; color:#991b1b; font-weight:600; margin-top:6px;">
                        <i class="fas fa-triangle-exclamation"></i>
                        Identité fiscale incomplète : {{ $manque->implode(', ') }}.
                    </div>
                @endif
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="btn btn-primary btn-sm" style="padding:7px 14px;"
                        onclick="ouvrirLiaison(@js($dem->uuid), @js($dem->nom))">
                    <i class="fas fa-check"></i> Valider
                </button>
                <button type="button" class="btn btn-outline btn-sm" style="padding:7px 14px;"
                        {{-- L'`uuid`, et non le numéro de ligne : c'est lui que
                             portent les adresses, et le numéro dirait combien
                             d'entreprises la plateforme compte. --}}
                        onclick="ouvrirRefus(@js($dem->uuid), @js($dem->nom))">
                    <i class="fas fa-xmark"></i> Refuser
                </button>
            </div>
        </div>
    @endforeach
</div>
@endif

{{-- KPIs --}}
@php
    $liees    = $entreprises->filter(fn ($e) => $e->liaisonComptaflowActive())->count();
    $nonLiees = $entreprises->count() - $liees;
@endphp
<div class="liaison-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5; color:#10b981;"><i class="fas fa-link"></i></div>
        <div><div class="stat-val">{{ $liees }}</div><div class="stat-lbl">Entreprises liées à COMPTAFLOW</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb; color:#d97706;"><i class="fas fa-hourglass-half"></i></div>
        <div><div class="stat-val">{{ $demandes->count() }}</div><div class="stat-lbl">Demandes en attente</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f1f5f9; color:#64748b;"><i class="fas fa-unlink"></i></div>
        <div><div class="stat-val">{{ $nonLiees }}</div><div class="stat-lbl">Entreprises sans liaison</div></div>
    </div>
</div>

{{-- Tableau des liaisons --}}
<div class="card">
    <div style="padding:18px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
        <h3 style="font-size:15px; font-weight:700; margin:0;"><i class="fas fa-table"></i> Tableau croisé des liaisons</h3>
        <span style="font-size:12px; color:var(--text-3);">{{ $entreprises->count() }} entreprise(s) Selflow</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Entreprise Selflow</th>
                    <th>ID Selflow</th>
                    <th>ID COMPTAFLOW</th>
                    <th>Statut liaison</th>
                    <th>Clé</th>
                    <th>Dernier déversement</th>
                    <th>Admin</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entreprises as $ent)
                @php
                    $admin = $ent->utilisateurs->first();
                    $estLiee = $ent->liaisonComptaflowActive();
                @endphp
                <tr class="ent-row">
                    <td>
                        <div style="font-weight:700; color:var(--text-1);">{{ $ent->nom }}</div>
                        <div style="font-size:11px; color:var(--text-3);">{{ $ent->forme_juridique }} · {{ is_array($ent->secteur_activite) ? implode(', ', $ent->secteur_activite) : ($ent->secteur_activite ?? '—') }}</div>
                        @if($ent->rccm)
                            <div style="font-size:10px; color:var(--text-3); font-family:monospace;">RCCM: {{ $ent->rccm }}</div>
                        @endif
                    </td>
                    <td>
                        <span style="background:var(--bg3); color:var(--primary); padding:3px 8px; border-radius:6px; font-family:monospace; font-weight:700; font-size:12px;">
                            #{{ $ent->id }}
                        </span>
                    </td>
                    <td>
                        @if($ent->comptaflow_company_id)
                            <span style="background:#dbeafe; color:#1d4ed8; padding:3px 8px; border-radius:6px; font-family:monospace; font-weight:700; font-size:12px;">
                                #{{ $ent->comptaflow_company_id }}
                            </span>
                            @if(isset($dossiersComptaflow[(int) $ent->comptaflow_company_id]))
                                <div style="font-size:11.5px; color:var(--text-2); margin-top:4px; font-weight:600;">{{ $dossiersComptaflow[(int) $ent->comptaflow_company_id] }}</div>
                            @endif
                        @else
                            <span style="color:var(--text-3); font-size:12px;">—</span>
                        @endif
                    </td>
                    <td>
                        @if($estLiee)
                            <span class="liaison-badge badge-active">
                                <i class="fas fa-circle" style="font-size:7px;"></i> Active
                            </span>
                            <span class="direction-arrow">
                                <i class="fas fa-arrow-right arrow-icon"></i> selflow déverse
                            </span>
                        @elseif($ent->demandeComptaflowEnAttente())
                            <span class="liaison-badge badge-attente">
                                <i class="fas fa-hourglass-half" style="font-size:9px;"></i> Demande en attente
                            </span>
                        @elseif($ent->comptaflow_demande_statut === \App\Modules\Admin\Modeles\Entreprise::DEMANDE_REFUSEE)
                            <span class="liaison-badge badge-error" title="{{ $ent->comptaflow_refus_motif }}">
                                <i class="fas fa-xmark" style="font-size:9px;"></i> Refusée
                            </span>
                        @else
                            <span class="liaison-badge badge-inactive">
                                <i class="fas fa-unlink" style="font-size:9px;"></i> Non liée
                            </span>
                        @endif
                    </td>
                    {{-- Quatre caractères : de quoi distinguer deux liaisons,
                         pas de quoi s'en servir. Aucun écran n'affiche jamais
                         une clé entière, ni ne permet de la copier. --}}
                    <td style="font-family:monospace; font-size:12px; color:var(--text-2);">
                        {{ $ent->indiceCleComptaflow() ?? '—' }}
                        @if($estLiee)
                            @php
                                $tournee = $ent->comptaflow_cle_tournee_le ?? $ent->comptaflow_liee_le;
                                $enRetard = $tournee && $tournee->lte(now()->subDays(\App\Modules\Admin\Services\LiaisonComptaflowService::JOURS_AVANT_ROTATION));
                            @endphp
                            <div style="font-family:inherit; font-size:10.5px; color:{{ $enRetard ? '#b45309' : 'var(--text-3)' }}; margin-top:3px;">
                                {{ $enRetard ? 'à renouveler — ' : '' }}{{ $tournee?->format('d/m/Y') ?? '—' }}
                            </div>
                            @if($ent->comptaflow_rotation_echouee_le)
                                <div style="font-family:inherit; font-size:10.5px; color:#991b1b; margin-top:2px;">
                                    dernier essai en échec le {{ $ent->comptaflow_rotation_echouee_le->format('d/m') }}
                                </div>
                            @endif
                        @endif
                    </td>
                    <td style="font-size:12px; color:var(--text-2);">
                        @if($ent->comptaflow_last_sync_at)
                            {{ $ent->comptaflow_last_sync_at->format('d/m/Y H:i') }}
                        @else
                            <span style="color:var(--text-3);">—</span>
                        @endif
                    </td>
                    <td>
                        @if($admin)
                            <div style="font-weight:600; font-size:13px;">{{ $admin->prenom }} {{ $admin->nom }}</div>
                            <div style="font-size:11px; color:var(--text-3);">{{ $admin->email }}</div>
                        @else
                            <span style="color:var(--text-3); font-size:12px;">—</span>
                        @endif
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                            @if($estLiee)
                                <form method="POST" action="{{ route('superadmin.liaisons.verifier', $ent) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm" title="Vérifier la liaison auprès de Comptaflow" style="padding:5px 8px;">
                                        <i class="fas fa-satellite-dish"></i>
                                    </button>
                                </form>
                                {{-- Sans attendre le premier du mois. Ne coupe
                                     rien : l'ancienne clé reste en place tant
                                     que la nouvelle n'est pas en main. --}}
                                <form method="POST" action="{{ route('superadmin.liaisons.renouveler_cle', $ent) }}" style="display:inline;"
                                      onsubmit="return confirm('Renouveler la clé de « {{ $ent->nom }} » ? L\'ancienne cessera de valoir.')">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm" title="Renouveler la clé maintenant" style="padding:5px 8px;">
                                        <i class="fas fa-key"></i>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('superadmin.liaisons.delierEntreprise', $ent) }}"
                                    onsubmit="return confirm('Délier « {{ $ent->nom }} » ? La clé sera révoquée chez Comptaflow.')" style="display:inline;">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm" title="Révoquer la clé et supprimer la liaison" style="padding:5px 8px;">
                                        <i class="fas fa-unlink"></i>
                                    </button>
                                </form>
                            @elseif($ent->demandeComptaflowEnAttente())
                                <button type="button" class="btn btn-primary btn-sm" style="padding:5px 10px; font-size:11px;"
                                        onclick="ouvrirLiaison(@js($ent->uuid), @js($ent->nom))">
                                    <i class="fas fa-check"></i> Valider
                                </button>
                            @else
                                {{-- Le superadministrateur ouvre le dossier de
                                     sa propre initiative : un client qui
                                     souscrit Comptaflow par téléphone n'a pas à
                                     cliquer dans un écran pour l'obtenir.
                                     La clé reste délivrée par Comptaflow —
                                     personne ne la saisit. --}}
                                <button type="button" class="btn btn-primary btn-sm" style="padding:5px 10px; font-size:11px;"
                                        onclick="ouvrirLiaison(@js($ent->uuid), @js($ent->nom))">
                                    <i class="fas fa-plug"></i> Lier maintenant
                                </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@if(!empty($comptaflowCompanies))
<div class="card" style="margin-top:24px; padding:18px 20px;">
    <h3 style="font-size:14px; font-weight:700; margin:0 0 10px;">
        <i class="fas fa-book"></i> Dossiers connus de Comptaflow
    </h3>
    <div style="font-size:12px; color:var(--text-3); line-height:1.6;">
        {{ count($comptaflowCompanies) }} dossier(s) répondent côté Comptaflow. Cette liste sert
        au rapprochement ; pour rattacher une entreprise à l'un d'eux, « Lier maintenant » puis « Lier à un dossier existant ».
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- MODAL : refuser une demande, avec un motif                  --}}
{{-- Un refus muet laisserait l'entreprise redemander sans fin.  --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
<div class="modal-overlay" id="modalRefus">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                <i class="fas fa-xmark" style="color:var(--danger)"></i>
                Refuser la demande de <span id="refus-nom"></span>
            </h3>
            <button type="button" onclick="document.getElementById('modalRefus').style.display='none'"
                    style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-3);">&times;</button>
        </div>
        <form method="POST" id="form-refus" action="" style="margin:0;">
            @csrf
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Motif <span style="color:var(--danger)">*</span></label>
                    <textarea name="motif" class="form-control" rows="3" required maxlength="255"
                              placeholder="Ex : le NCC déclaré ne correspond à aucune entreprise connue de la DGI."></textarea>
                    <small style="color:var(--text-3); font-size:11px;">
                        L'entreprise ne verra que ce texte, dans ses paramètres.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modalRefus').style.display='none'">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-xmark"></i> Refuser</button>
            </div>
        </form>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- MODAL : ouvrir la liaison (retour du 16/10/2026)            --}}
{{-- Créer un dossier ou lier un dossier existant ; déverser     --}}
{{-- maintenant ou plus tard ; les exercices Selflow à déverser. --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@php
    $dejaLies = $entreprises->pluck('comptaflow_company_id')->filter()->map(fn ($i) => (int) $i)->all();
    $exercicesParUuid = $entreprises->mapWithKeys(fn ($e) => [$e->uuid => [
        'annees'    => $exercices[$e->id] ?? [(int) now()->year],
        'demandees' => $anneesDemandees[$e->id] ?? [],
    ]]);
@endphp
<div class="modal-overlay" id="modalLiaison">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="font-size:15px; font-weight:700; margin:0;">
                <i class="fas fa-plug" style="color:var(--primary)"></i>
                Liaison Comptaflow — <span id="liaison-nom"></span>
            </h3>
            <button type="button" onclick="document.getElementById('modalLiaison').style.display='none'"
                    style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-3);">&times;</button>
        </div>
        <form method="POST" id="form-liaison" action="" style="margin:0;">
            @csrf
            <div class="modal-body" style="display:flex; flex-direction:column; gap:14px; font-size:13px;">
                <div>
                    <label class="form-label">Dossier Comptaflow</label>
                    <label style="display:flex; gap:8px; align-items:flex-start; margin-bottom:6px; cursor:pointer;">
                        <input type="radio" name="mode" value="creer" checked onchange="basculerModeLiaison()">
                        <span><strong>Créer un nouveau dossier</strong><br><small style="color:var(--text-3);">Les accès Selflow ouvrent le compte comptable (même adresse, même mot de passe).</small></span>
                    </label>
                    <label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer;">
                        <input type="radio" name="mode" value="lier" onchange="basculerModeLiaison()" {{ empty($dossiersComptaflow) ? 'disabled' : '' }}>
                        <span><strong>Lier à un dossier existant</strong><br><small style="color:var(--text-3);">{{ empty($dossiersComptaflow) ? 'Comptaflow ne répond pas ou ne connaît aucun dossier.' : 'Chaque côté garde ses accès d\'origine.' }}</small></span>
                    </label>
                    <select name="comptaflow_company_id" id="liaison-dossier" class="form-control" style="margin-top:8px; display:none;">
                        <option value="">— Choisir le dossier Comptaflow —</option>
                        @foreach($dossiersComptaflow as $idDossier => $nomDossier)
                            <option value="{{ $idDossier }}" {{ in_array($idDossier, $dejaLies, true) ? 'disabled' : '' }}>
                                #{{ $idDossier }} — {{ $nomDossier }}{{ in_array($idDossier, $dejaLies, true) ? ' (déjà lié)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Exercices Selflow à déverser</label>
                    <div id="liaison-annees" style="display:flex; gap:14px; flex-wrap:wrap;"></div>
                    <small style="color:var(--text-3); font-size:11px;">L'exercice en cours part toujours. Les exercices cochés en jaune ont été demandés par l'entreprise.</small>
                </div>
                <label style="display:flex; gap:8px; align-items:flex-start; cursor:pointer; padding:10px 12px; background:var(--bg3); border-radius:8px;">
                    <input type="hidden" name="deverser" value="0">
                    <input type="checkbox" name="deverser" value="1" checked>
                    <span><strong>Déverser les écritures maintenant</strong><br><small style="color:var(--text-3);">Décoché : seul le référentiel part ; l'historique se déversera plus tard, depuis les paramètres de l'entreprise.</small></span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modalLiaison').style.display='none'">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plug"></i> Ouvrir la liaison</button>
            </div>
        </form>
    </div>
</div>

<script>
    var GABARIT_REFUS = "{{ route('superadmin.liaisons.refuser', ['entreprise' => '__ID__']) }}";
    var GABARIT_VALIDER = "{{ route('superadmin.liaisons.valider', ['entreprise' => '__ID__']) }}";
    var EXERCICES = @json($exercicesParUuid);
    var ANNEE_EN_COURS = {{ (int) now()->year }};
    function ouvrirLiaison(id, nom) {
        document.getElementById('liaison-nom').textContent = nom;
        document.getElementById('form-liaison').action = GABARIT_VALIDER.replace('__ID__', id);
        var infos = EXERCICES[id] || { annees: [ANNEE_EN_COURS], demandees: [] };
        var boite = document.getElementById('liaison-annees');
        boite.innerHTML = '';
        infos.annees.forEach(function (annee) {
            var courant = annee >= ANNEE_EN_COURS;
            var demandee = infos.demandees.indexOf(annee) !== -1;
            var label = document.createElement('label');
            label.style.cssText = 'display:inline-flex;align-items:center;gap:5px;font-weight:600;padding:4px 8px;border-radius:6px;' + (demandee ? 'background:#fef9c3;' : '');
            var c = document.createElement('input');
            c.type = 'checkbox'; c.name = 'annees[]'; c.value = annee;
            c.checked = courant || demandee; c.disabled = courant;
            label.appendChild(c);
            label.appendChild(document.createTextNode(' ' + annee + (courant ? ' (en cours)' : '')));
            boite.appendChild(label);
        });
        document.querySelector('#form-liaison input[value=creer]').checked = true;
        basculerModeLiaison();
        document.getElementById('modalLiaison').style.display = 'flex';
    }
    function basculerModeLiaison() {
        var lier = document.querySelector('#form-liaison input[name=mode]:checked').value === 'lier';
        var choix = document.getElementById('liaison-dossier');
        choix.style.display = lier ? 'block' : 'none';
        choix.required = lier;
    }

    function ouvrirRefus(id, nom) {
        document.getElementById('refus-nom').textContent = nom;
        document.getElementById('form-refus').action = GABARIT_REFUS.replace('__ID__', id);
        document.getElementById('modalRefus').style.display = 'flex';
    }

    document.querySelectorAll('.modal-overlay').forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) m.style.display = 'none'; });
    });
</script>
@endsection
