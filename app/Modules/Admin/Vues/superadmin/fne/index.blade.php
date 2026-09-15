@extends('admin::gabarits.application')
@section('titre', 'Gestion FNE')
@section('topbar_titre', 'SuperAdmin — Gestion des clés FNE (DGI)')

@section('styles')
<style>
    .stat-card {
        background: #fff; border: 1px solid var(--border); border-radius: 14px;
        padding: 20px 24px; display: flex; align-items: center; gap: 16px;
    }
    .stat-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
    .stat-val { font-size: 28px; font-weight: 800; color: var(--text-1); }
    .stat-lbl { font-size: 12px; color: var(--text-3); text-transform: uppercase; font-weight: 700; letter-spacing: .5px; }

    .fne-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; }
    .badge-validee       { background:#ecfdf5; color:#065f46; }
    .badge-test          { background:#fffbeb; color:#92400e; }
    .badge-non_configure { background:#f1f5f9; color:#64748b; }

    .cle-chip {
        font-family: 'JetBrains Mono', 'Courier New', monospace; font-size: 12px;
        background: #f8fafc; border: 1px solid var(--border); border-radius: 6px;
        padding: 3px 8px; color: var(--text-2); display:inline-flex; align-items:center; gap:6px;
    }

    .ent-row td { vertical-align: middle; padding: 12px 14px; }

    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center; }
    .modal-box     { background:#fff; border-radius:16px; max-width:480px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,.15); overflow:hidden; }
    .modal-header  { padding:18px 22px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
    .modal-body    { padding:22px; }
    .modal-footer  { padding:14px 22px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:10px; }

    .alert-warn { background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:12px 14px; color:#92400e; font-size:13px; margin-bottom:14px; display:flex; gap:8px; align-items:flex-start; }
</style>
@endsection

@section('contenu')

@if(session('success'))
<div style="background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; padding:14px 18px; margin-bottom:20px; color:#065f46; font-weight:600;">
    <i class="fas fa-check-circle"></i> {{ session('success') }}
</div>
@endif
@if(session('error'))
<div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; padding:14px 18px; margin-bottom:20px; color:#991b1b; font-weight:600;">
    <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
</div>
@endif

<div class="page-header">
    <div>
        <h1><i class="fas fa-key"></i> Gestion des clés FNE</h1>
        <p>Chaque entreprise doit disposer de sa propre clé DGI (il n'existe pas de clé unique partagée). Clé de test d'abord, puis clé réelle après validation par la DGI.</p>
    </div>
</div>

<div class="alert-warn">
    <i class="fas fa-shield-halved" style="margin-top:2px;"></i>
    <div>
        Les clés sont chiffrées en base (AES-256, dérivée de la clé applicative du serveur — jamais stockées en clair).
        Toute consultation, modification ou suppression exige la re-saisie de <strong>votre</strong> mot de passe superadmin.
        Chaque action sur une clé est journalisée (logs applicatifs).
    </div>
</div>

@php
    $nbValidees = $entreprises->where('statut', 'validee')->count();
    $nbTest     = $entreprises->where('statut', 'test')->count();
    $nbAucune   = $entreprises->where('statut', 'non_configure')->count();
@endphp

<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:28px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5; color:#059669;"><i class="fas fa-circle-check"></i></div>
        <div><div class="stat-val">{{ $nbValidees }}</div><div class="stat-lbl">Clé réelle active</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb; color:#d97706;"><i class="fas fa-flask"></i></div>
        <div><div class="stat-val">{{ $nbTest }}</div><div class="stat-lbl">En phase de test</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f1f5f9; color:#64748b;"><i class="fas fa-circle-xmark"></i></div>
        <div><div class="stat-val">{{ $nbAucune }}</div><div class="stat-lbl">Non connectées</div></div>
    </div>
</div>

{{-- La carte portait `overflow:hidden` : sur sept colonnes, la fin du tableau
     — les clés, et la colonne Actions qui permet de les poser — était coupée à
     droite **sans barre de défilement**. Il n'existait aucun moyen de
     l'atteindre, et c'est justement la partie qui sert.

     La carte laisse maintenant défiler, et la colonne Actions reste collée à
     droite : sur un écran étroit on fait défiler pour lire les clés, sans
     jamais perdre les boutons. --}}
<div class="content-card" style="background:#fff; border:1px solid var(--border); border-radius:14px; overflow-x:auto;">
    <table style="width:100%; min-width:1080px; border-collapse:collapse;">
        <thead style="background:#f8fafc; border-bottom:1px solid var(--border);">
            <tr>
                <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:var(--text-3);">Entreprise</th>
                <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:var(--text-3);">Statut</th>
                <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:var(--text-3);">Clé de test</th>
                <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:var(--text-3);">Clé réelle</th>
                {{-- Ce que l'entreprise a fourni de son côté : c'est tout ce
                     qu'on lui demande, la configuration se fait ici. --}}
                <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:var(--text-3);">Compte FNE</th>
                <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:var(--text-3);">Timbre</th>
                {{-- Collée à droite : on fait défiler pour lire les clés sans perdre les boutons. --}}
                <th style="text-align:right; padding:12px 14px; font-size:12px; text-transform:uppercase; color:var(--text-3); position:sticky; right:0; background:#f8fafc; box-shadow:-6px 0 8px -6px rgba(0,0,0,.15);">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entreprises as $row)
            <tr class="ent-row" style="border-bottom:1px solid var(--border);">
                <td>
                    <div style="font-weight:700; color:var(--text-1);">{{ $row['entreprise']->nom }}</div>
                    <div style="font-size:12px; color:var(--text-3);">NCC : {{ $row['entreprise']->ncc ?? '—' }}</div>
                </td>
                <td>
                    <span class="fne-badge badge-{{ $row['statut'] }}">
                        @if($row['statut'] === 'validee') <i class="fas fa-circle-check"></i>
                        @elseif($row['statut'] === 'test') <i class="fas fa-flask"></i>
                        @else <i class="fas fa-circle-xmark"></i>
                        @endif
                        {{ $row['statut_label'] }}
                    </span>
                </td>
                <td>
                    @if($row['credential'] && $row['credential']->cle_test)
                        <span class="cle-chip"><i class="fas fa-lock"></i> {{ $row['cle_test_masquee'] }}</span>
                    @else
                        <span style="color:var(--text-3); font-size:12px;">Non renseignée</span>
                    @endif
                </td>
                <td>
                    @if($row['credential'] && $row['credential']->cle_reelle)
                        <span class="cle-chip"><i class="fas fa-lock"></i> {{ $row['cle_reelle_masquee'] }}</span>
                    @else
                        <span style="color:var(--text-3); font-size:12px;">Non renseignée</span>
                    @endif
                </td>
                <td>
                    @if($row['possede_compte_fne'] === true)
                        <span style="font-size:12px; font-weight:600; color:var(--success);">
                            <i class="fas fa-circle-check"></i> Déclaré
                        </span>
                    @elseif($row['possede_compte_fne'] === false)
                        <span style="font-size:12px; font-weight:600; color:#b45309;">
                            <i class="fas fa-circle-plus"></i> À ouvrir
                        </span>
                    @else
                        <span style="font-size:12px; color:var(--text-3);">
                            <i class="fas fa-circle-question"></i> Pas répondu
                        </span>
                    @endif
                    <div style="font-size:11px; color:{{ $row['informations_manquantes'] > 0 ? '#b45309' : 'var(--text-3)' }}; margin-top:2px;">
                        @if($row['informations_manquantes'] > 0)
                            {{ $row['informations_manquantes'] }} information(s) manquante(s)
                        @else
                            Informations complètes
                        @endif
                    </div>
                </td>
                <td>
                    {{-- Ce réglage vivait dans les paramètres de l'entreprise,
                         sous une étiquette « informatif » qui promettait qu'il
                         ne changeait aucun montant. Il décide en réalité si le
                         droit de timbre est réclamé au client. --}}
                    <form method="POST" action="{{ route('superadmin.fne.timbre', $row['entreprise']) }}" style="margin:0;">
                        @csrf
                        <input type="hidden" name="timbre_quittance" value="{{ $row['timbre_quittance'] ? '0' : '1' }}">
                        <button type="submit" class="btn btn-outline"
                                style="font-size:11.5px; padding:5px 9px; white-space:nowrap; color:{{ $row['timbre_quittance'] ? 'var(--success)' : 'var(--text-3)' }};"
                                title="Reporter l'option cochée sur l'espace FNE de l'entreprise">
                            <i class="fas {{ $row['timbre_quittance'] ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                            {{ $row['timbre_quittance'] ? 'Appliqué' : 'Non appliqué' }}
                        </button>
                    </form>
                </td>
                <td style="text-align:right; position:sticky; right:0; background:#fff; box-shadow:-6px 0 8px -6px rgba(0,0,0,.15);">
                    {{-- L'identifiant public, et non le numéro de ligne : les routes de
                         l'entreprise se lient par `uuid` (IdentifiantOpaque). Avec le
                         numéro, chaque appel du modal répondait 404, et l'écran le
                         prenait pour une coupure réseau. --}}
                    <button class="btn btn-outline" style="font-size:12px; padding:6px 10px;"
                            onclick="ouvrirModalGestion(@js($row['entreprise']->getRouteKey()), @js($row['entreprise']->nom))">
                        <i class="fas fa-gear"></i> Gérer
                    </button>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" style="padding:30px; text-align:center; color:var(--text-3);">Aucune entreprise.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ═══════════════ MODAL DE GESTION (ajouter / voir / supprimer) ═══════════════ --}}
<div class="modal-overlay" id="modalGestionFne">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-header">
            <h3 style="margin:0;"><i class="fas fa-key"></i> Clés FNE — <span id="modalEntNom"></span></h3>
            <button onclick="fermerModalGestion()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">

            {{-- Étape 1 : demande du mot de passe pour toute action --}}
            <div id="etapeMotDePasse">
                <p style="font-size:13px; color:var(--text-2); margin-bottom:12px;">
                    Saisissez votre mot de passe superadmin pour accéder à la gestion des clés de cette entreprise.
                </p>
                <div class="form-group">
                    <label class="form-label">Votre mot de passe</label>
                    <input type="password" id="inputMotDePasseGestion" class="form-control" placeholder="••••••••">
                </div>
                <div id="erreurMdp" style="color:#991b1b; font-size:13px; margin-top:6px; display:none;"></div>
                <button type="button" class="btn btn-primary" style="margin-top:14px; width:100%;" onclick="verifierMotDePasseEtOuvrirGestion()">
                    <i class="fas fa-unlock"></i> Déverrouiller
                </button>
            </div>

            {{-- Étape 2 : gestion effective (affichée après mot de passe validé) --}}
            <div id="etapeGestion" style="display:none;">

                <div style="margin-bottom:18px; padding-bottom:18px; border-bottom:1px solid var(--border);">
                    <h4 style="font-size:14px; margin:0 0 8px;"><i class="fas fa-flask" style="color:#d97706;"></i> Clé de test</h4>
                    <div id="cleTestAffichage" style="margin-bottom:8px; font-family:monospace; font-size:13px; word-break:break-all;"></div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <input type="text" id="nouvelleCleTest" class="form-control" placeholder="Nouvelle clé de test" style="flex:1; min-width:180px;">
                        <button type="button" class="btn btn-primary" style="font-size:12px;" onclick="soumettreCle('test')">Enregistrer</button>
                        <button type="button" class="btn btn-outline" style="font-size:12px; color:var(--danger); border-color:var(--danger);" onclick="supprimerCle('test')">Supprimer</button>
                    </div>
                </div>

                <div>
                    <h4 style="font-size:14px; margin:0 0 8px;"><i class="fas fa-circle-check" style="color:#059669;"></i> Clé réelle (production)</h4>
                    <div id="cleReelleAffichage" style="margin-bottom:8px; font-family:monospace; font-size:13px; word-break:break-all;"></div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <input type="text" id="nouvelleCleReelle" class="form-control" placeholder="Nouvelle clé réelle" style="flex:1; min-width:180px;">
                        <button type="button" class="btn btn-primary" style="font-size:12px;" onclick="soumettreCle('reelle')">Activer</button>
                        <button type="button" class="btn btn-outline" style="font-size:12px; color:var(--danger); border-color:var(--danger);" onclick="supprimerCle('reelle')">Supprimer</button>
                    </div>
                </div>

            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="fermerModalGestion()">Fermer</button>
        </div>
    </div>
</div>

<script>
let entrepriseCourante = null;
let motDePasseValide = null;

// Modèles d'URL injectés côté serveur (via route()) — jamais codés en dur,
// évite tout problème si l'application est un jour servie depuis un sous-chemin.
const URLS_FNE = {
    voirCle: "{{ route('superadmin.fne.voir_cle', ':id') }}",
    cleTest: "{{ route('superadmin.fne.cle_test', ':id') }}",
    cleReelle: "{{ route('superadmin.fne.cle_reelle', ':id') }}",
    supprimerCle: "{{ route('superadmin.fne.supprimer_cle', ':id') }}",
};
function urlFne(cle, entrepriseId) {
    return URLS_FNE[cle].replace(':id', entrepriseId);
}

// Une réponse qui n'est pas du JSON — la page 404 d'une adresse fausse, la
// page 419 d'une session expirée — faisait lever `res.json()`, et le `catch`
// l'annonçait « Erreur réseau ». Le serveur avait pourtant répondu : on dit ce
// qu'il a répondu.
async function lireJson(res) {
    try {
        return await res.json();
    } catch (e) {
        return { illisible: true };
    }
}

function afficherErreurMdp(message) {
    document.getElementById('erreurMdp').textContent = message;
    document.getElementById('erreurMdp').style.display = 'block';
}

function ouvrirModalGestion(entrepriseId, nom) {
    entrepriseCourante = entrepriseId;
    motDePasseValide = null;
    document.getElementById('modalEntNom').textContent = nom;
    document.getElementById('etapeMotDePasse').style.display = 'block';
    document.getElementById('etapeGestion').style.display = 'none';
    document.getElementById('inputMotDePasseGestion').value = '';
    document.getElementById('erreurMdp').style.display = 'none';
    document.getElementById('cleTestAffichage').textContent = '';
    document.getElementById('cleReelleAffichage').textContent = '';
    document.getElementById('modalGestionFne').style.display = 'flex';
}

function fermerModalGestion() {
    document.getElementById('modalGestionFne').style.display = 'none';
    motDePasseValide = null; // le mot de passe n'est jamais conservé au-delà de la modale ouverte
}

async function verifierMotDePasseEtOuvrirGestion() {
    const mdp = document.getElementById('inputMotDePasseGestion').value;
    if (!mdp) return;

    // On tente une "révélation" de la clé test (ou réelle) comme moyen de
    // vérifier le mot de passe ET récupérer la clé en une seule requête.
    try {
        const res = await fetch(urlFne('voirCle', entrepriseCourante), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ mot_de_passe: mdp, type: 'test' })
        });
        const data = await lireJson(res);

        if (data.illisible) {
            afficherErreurMdp(res.status === 419
                ? 'Votre session a expiré. Rechargez la page, puis réessayez.'
                : `Le serveur a répondu ${res.status} sans réponse lisible. Rechargez la page, puis réessayez.`);
            return;
        }

        if (res.status === 403) {
            afficherErreurMdp(data.message || 'Mot de passe incorrect.');
            return;
        }

        // Mot de passe correct (que la clé existe ou non — 404 = pas encore de clé, ce n'est pas une erreur de mot de passe)
        motDePasseValide = mdp;
        document.getElementById('etapeMotDePasse').style.display = 'none';
        document.getElementById('etapeGestion').style.display = 'block';
        document.getElementById('cleTestAffichage').innerHTML = data.success
            ? `<span class="cle-chip"><i class="fas fa-lock-open"></i> ${data.cle}</span>`
            : '<span style="color:var(--text-3); font-size:12px;">Non renseignée</span>';

        // Récupérer aussi la clé réelle pour affichage
        const res2 = await fetch(urlFne('voirCle', entrepriseCourante), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ mot_de_passe: mdp, type: 'reelle' })
        });
        const data2 = await lireJson(res2);
        document.getElementById('cleReelleAffichage').innerHTML = data2.success
            ? `<span class="cle-chip"><i class="fas fa-lock-open"></i> ${data2.cle}</span>`
            : '<span style="color:var(--text-3); font-size:12px;">Non renseignée</span>';

    } catch (e) {
        afficherErreurMdp('Erreur réseau. Réessayez.');
    }
}

function soumettreCle(type) {
    if (!motDePasseValide) return;
    const valeur = document.getElementById(type === 'test' ? 'nouvelleCleTest' : 'nouvelleCleReelle').value.trim();
    if (!valeur) { alert('Veuillez saisir une clé.'); return; }

    if (type === 'reelle') {
        const confirmMsg = "⚠️ ATTENTION : Vous êtes sur le point d'activer la clé API de PRODUCTION.\n\n" +
                           "Cette action va basculer l'entreprise en mode réel (DGI officiel) et SUPPRIMER DÉFINITIVEMENT toutes les factures, avoirs et achats de test (sandbox) enregistrés pour repartir sur une base saine et propre.\n\n" +
                           "Confirmez-vous cette opération de passage en production ?";
        if (!confirm(confirmMsg)) return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = urlFne(type === 'test' ? 'cleTest' : 'cleReelle', entrepriseCourante);
    form.innerHTML = `
        @csrf
        <input type="hidden" name="cle_${type === 'test' ? 'test' : 'reelle'}" value="${valeur.replace(/"/g, '&quot;')}">
        <input type="hidden" name="mot_de_passe" value="${motDePasseValide.replace(/"/g, '&quot;')}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function supprimerCle(type) {
    if (!motDePasseValide) return;
    if (!confirm(`Confirmez-vous la suppression de la clé ${type} ? Cette action est irréversible.`)) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = urlFne('supprimerCle', entrepriseCourante);
    form.innerHTML = `
        @csrf
        @method('DELETE')
        <input type="hidden" name="type" value="${type}">
        <input type="hidden" name="mot_de_passe" value="${motDePasseValide.replace(/"/g, '&quot;')}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

@endsection
