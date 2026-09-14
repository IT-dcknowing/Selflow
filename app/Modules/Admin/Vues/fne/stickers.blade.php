@extends('admin::gabarits.application')
@section('titre', 'Gestion des Stickers')
@section('topbar_titre', 'Fiscalité & DGI — Gestion des Stickers')

@section('styles')
<style>
    .kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:26px; }
    .kpi-card {
        background:#fff; border:1px solid var(--border); border-radius:14px;
        padding:20px 24px; position:relative; overflow:hidden;
    }
    .kpi-card::before {
        content:''; position:absolute; top:0; left:0; width:4px; height:100%;
        background:var(--primary);
    }
    .kpi-card.alerte::before { background:#E53E3E; }
    .kpi-card .lbl { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); letter-spacing:.4px; margin-bottom:8px; }
    .kpi-card .val { font-size:32px; font-weight:800; color:var(--text-1); }
    .kpi-card .sub { font-size:12px; color:var(--text-3); margin-top:6px; }

    .stickers-table { width:100%; border-collapse:collapse; }
    .stickers-table th { text-align:left; padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-3); background:#f8fafc; border-bottom:1px solid var(--border); }
    .stickers-table td { padding:12px 14px; border-bottom:1px solid var(--border); font-size:13px; }
    .stickers-table tr:last-child td { border-bottom:none; }
    .stickers-table tr:hover td { background:var(--bg2); }

    /* Tabs navigation */
    .fne-tabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:24px; }
    .fne-tab  { padding:10px 20px; font-size:13px; font-weight:600; color:var(--text-3); cursor:pointer; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .15s; }
    .fne-tab:hover { color:var(--primary); }
    .fne-tab.active { color:var(--primary); border-bottom-color:var(--primary); }

    /* Modal sticker */
    @keyframes stickerFadeIn { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
    .sticker-modal-overlay {
        display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
        z-index:1000; align-items:center; justify-content:center;
    }
    .sticker-modal-overlay.open { display:flex; }
    .sticker-modal-box {
        background:#fff; border-radius:16px; padding:28px; max-width:480px; width:100%;
        box-shadow:0 25px 60px rgba(0,0,0,.18); animation:stickerFadeIn .2s ease;
    }
    .operateur-btn {
        flex:1; padding:12px 8px; border:2px solid var(--border); border-radius:10px;
        background:#fff; cursor:pointer; text-align:center; font-weight:700;
        font-size:12px; transition:all .15s;
    }
    .operateur-btn:hover, .operateur-btn.selected { border-color:var(--primary); background:#eff6ff; color:var(--primary); }
    .operateur-btn.selected { box-shadow:0 0 0 3px rgba(59,130,246,.15); }
</style>
@endsection

@section('contenu')

{{-- Alertes flash --}}
@if(session('succes'))
<div style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:20px;color:#065f46;font-weight:500;">
    <i class="fas fa-check-circle" style="font-size:16px;color:#10b981;"></i> {{ session('succes') }}
</div>
@endif

{{-- Onglets FNE --}}
<div class="fne-tabs">
    <a href="{{ route('admin.fne.gestion') }}"   class="fne-tab"><i class="fas fa-chart-bar"></i> Gestion</a>
    <a href="{{ route('admin.fne.situation') }}"  class="fne-tab"><i class="fas fa-balance-scale"></i> Situation</a>
    <a href="{{ route('admin.fne.factures') }}"   class="fne-tab"><i class="fas fa-file-invoice"></i> Factures</a>
    <a href="{{ route('admin.fne.stickers') }}"   class="fne-tab active"><i class="fas fa-stamp"></i> Stickers</a>
    <a href="{{ route('admin.fne.rejets') }}"     class="fne-tab"><i class="fas fa-triangle-exclamation"></i> Rejets</a>
</div>

{{-- Alerte solde bas --}}
@if($kpis['alerte'])
<div style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:#fff5f5;border:1px solid #fed7d7;border-radius:10px;margin-bottom:20px;color:#c53030;">
    <i class="fas fa-exclamation-triangle" style="font-size:18px;"></i>
    <div>
        <strong>Solde de stickers faible !</strong>
        Il vous reste <strong>{{ $kpis['soldeActuel'] }}</strong> sticker(s), en dessous du seuil d'alerte fixé à {{ $kpis['alerteSeuil'] }}.
        <a href="{{ route('admin.entreprise.parametres') }}" style="color:#c53030;text-decoration:underline;margin-left:4px;">Modifier le seuil</a>
    </div>
</div>
@endif

{{-- KPIs --}}
<div class="kpi-grid">
    <div class="kpi-card {{ $kpis['alerte'] ? 'alerte' : '' }}">
        <div class="lbl"><i class="fas fa-wallet"></i> Solde actuel</div>
        <div class="val" style="{{ $kpis['alerte'] ? 'color:#E53E3E;' : '' }}">{{ number_format($kpis['soldeActuel'], 0, ',', ' ') }}</div>
        <div class="sub">sticker(s) disponibles</div>
    </div>
    <div class="kpi-card">
        <div class="lbl"><i class="fas fa-arrow-down"></i> Total achetés</div>
        <div class="val">{{ number_format($kpis['totalAchetes'], 0, ',', ' ') }}</div>
        <div class="sub">transactions réussies</div>
    </div>
    <div class="kpi-card">
        <div class="lbl"><i class="fas fa-arrow-up"></i> Total consommés</div>
        <div class="val">{{ number_format($kpis['totalConsommes'], 0, ',', ' ') }}</div>
        <div class="sub">utilisés sur factures DGI</div>
    </div>
</div>

{{-- En-tête tableau --}}
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div style="font-size:14px;font-weight:700;color:var(--text-1);">
        <i class="fas fa-history" style="margin-right:8px;color:var(--primary);"></i>
        Historique des achats de stickers
    </div>
    <button id="btn-ouvrir-achat" class="btn btn-primary"
        onclick="document.getElementById('modalAchatSticker').classList.add('open')">
        <i class="fas fa-plus"></i> Achat de stickers
    </button>
</div>

{{-- Tableau historique --}}
<div class="card" style="overflow:hidden;">
    <div class="table-wrap">
        <table class="stickers-table">
            <thead>
                <tr>
                    <th>ID Syst.</th>
                    <th>Date d'achat</th>
                    <th>Opérateur</th>
                    <th>Téléphone</th>
                    <th>Nb stickers</th>
                    <th>Montant (FCFA)</th>
                    <th>Statut</th>
                    <th>ID Transaction</th>
                </tr>
            </thead>
            <tbody id="tbody-stickers">
                @forelse($transactions as $tx)
                <tr>
                    <td style="font-family:monospace;font-weight:700;color:var(--primary);">#{{ $tx->id }}</td>
                    <td style="color:var(--text-3);">{{ $tx->created_at->format('d/m/Y H:i') }}</td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:12px;padding:3px 10px;border-radius:20px;
                            background:{{ $tx->operateur === 'ORANGE' ? '#fff7ed' : ($tx->operateur === 'MTN' ? '#fefce8' : '#eff6ff') }};
                            color:{{ $tx->operateur === 'ORANGE' ? '#c2410c' : ($tx->operateur === 'MTN' ? '#713f12' : '#1d4ed8') }};">
                            {{ $tx->operateur }}
                        </span>
                    </td>
                    <td style="font-family:monospace;">{{ $tx->telephone }}</td>
                    <td style="font-weight:700;font-size:16px;color:var(--text);">{{ number_format($tx->nombre_stickers, 0, ',', ' ') }}</td>
                    <td style="font-weight:700;">{{ number_format($tx->montant, 0, ',', ' ') }} F</td>
                    <td>
                        <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;{{ $tx->statutBadgeStyle() }}">
                            {{ $tx->statutLabel() }}
                        </span>
                    </td>
                    <td style="font-family:monospace;font-size:11px;color:var(--text-3);">
                        {{ $tx->id_transaction_externe ?? '—' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;padding:48px;color:var(--text-3);">
                        <i class="fas fa-stamp" style="font-size:40px;display:block;margin-bottom:12px;opacity:.2;"></i>
                        Aucune transaction de stickers enregistrée pour le moment.<br>
                        <small>Cliquez sur <strong>Achat de stickers</strong> pour commencer.</small>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($transactions->hasPages())
        <div style="padding:16px;">{{ $transactions->links() }}</div>
        @endif
    </div>
</div>

<p style="font-size:11px;color:var(--text-3);margin-top:12px;">
    <i class="fas fa-info-circle"></i>
    Prix unitaire : <strong>20 FCFA / sticker</strong>.
    Le statut passe de <em>En attente</em> à <em>Succès</em> après confirmation de paiement Mobile Money.
</p>

{{-- ─────────────────── Modal Achat de Stickers ─────────────────── --}}
<div class="sticker-modal-overlay" id="modalAchatSticker">
    <div class="sticker-modal-box">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:var(--text-1);">
                <i class="fas fa-stamp" style="color:var(--primary);margin-right:8px;"></i>Achat de stickers DGI
            </h3>
            <button onclick="fermerModalSticker()" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--text-3);">✕</button>
        </div>

        {{-- Info prix --}}
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#eff6ff;border-radius:10px;border:1px solid #bfdbfe;margin-bottom:20px;">
            <i class="fas fa-info-circle" style="color:#1d4ed8;font-size:16px;"></i>
            <span style="font-size:13px;color:#1e40af;font-weight:500;">Prix unitaire : <strong>20 FCFA / sticker</strong></span>
        </div>

        <form id="formAchatSticker" onsubmit="soumettreAchatSticker(event)">
            @csrf

            {{-- Opérateur --}}
            <div class="form-group">
                <label class="form-label">Opérateur Mobile Money <span style="color:#E53E3E">*</span></label>
                <div style="display:flex;gap:10px;">
                    <button type="button" class="operateur-btn" id="op-ORANGE" onclick="selectOperateur('ORANGE')">
                        <i class="fas fa-mobile-alt" style="font-size:18px;margin-bottom:4px;display:block;color:#ea580c;"></i>
                        ORANGE Money
                    </button>
                    <button type="button" class="operateur-btn" id="op-MOOV" onclick="selectOperateur('MOOV')">
                        <i class="fas fa-mobile-alt" style="font-size:18px;margin-bottom:4px;display:block;color:#1d4ed8;"></i>
                        MOOV Money
                    </button>
                    <button type="button" class="operateur-btn" id="op-MTN" onclick="selectOperateur('MTN')">
                        <i class="fas fa-mobile-alt" style="font-size:18px;margin-bottom:4px;display:block;color:#ca8a04;"></i>
                        MTN MoMo
                    </button>
                </div>
                <input type="hidden" name="operateur" id="input-operateur" required>
            </div>

            {{-- Téléphone --}}
            <div class="form-group">
                <label class="form-label">Numéro de téléphone <span style="color:#E53E3E">*</span></label>
                <input type="tel" name="telephone" id="input-telephone" class="form-control"
                    placeholder="Ex: 0707022259" required maxlength="30">
            </div>

            {{-- Nombre de stickers + montant auto --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Nombre de stickers <span style="color:#E53E3E">*</span></label>
                    <input type="number" name="nombre_stickers" id="input-nombre" class="form-control"
                        placeholder="Ex: 50" min="1" max="10000" required
                        oninput="calculerMontant()">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Montant total (FCFA)</label>
                    <input type="text" id="display-montant" class="form-control"
                        value="—" readonly
                        style="background:var(--bg2);font-weight:700;color:var(--primary);cursor:not-allowed;">
                </div>
            </div>

            {{-- Message feedback --}}
            <div id="achat-feedback" style="display:none;margin-top:14px;"></div>

            {{-- Boutons --}}
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="fermerModalSticker()">Annuler</button>
                <button type="submit" class="btn btn-primary" id="btn-soumettre-achat">
                    <i class="fas fa-check"></i> Valider le paiement
                </button>
            </div>
        </form>
    </div>
</div>

@endsection

@section('scripts')
<script>
// ── Sélection opérateur ──
let operateurSelectionne = null;

function selectOperateur(op) {
    operateurSelectionne = op;
    ['ORANGE','MOOV','MTN'].forEach(o => {
        document.getElementById('op-' + o).classList.toggle('selected', o === op);
    });
    document.getElementById('input-operateur').value = op;
}

// ── Calcul automatique du montant ──
function calculerMontant() {
    const nb = parseInt(document.getElementById('input-nombre').value) || 0;
    document.getElementById('display-montant').value = nb > 0
        ? new Intl.NumberFormat('fr-FR').format(nb * 20) + ' FCFA'
        : '—';
}

// ── Fermer modal ──
function fermerModalSticker() {
    document.getElementById('modalAchatSticker').classList.remove('open');
    document.getElementById('formAchatSticker').reset();
    document.getElementById('display-montant').value = '—';
    ['ORANGE','MOOV','MTN'].forEach(o => document.getElementById('op-' + o).classList.remove('selected'));
    operateurSelectionne = null;
    document.getElementById('input-operateur').value = '';
    document.getElementById('achat-feedback').style.display = 'none';
}

// Fermer en cliquant sur l'overlay
document.getElementById('modalAchatSticker').addEventListener('click', function(e) {
    if (e.target === this) fermerModalSticker();
});

// ── Soumettre achat AJAX ──
function soumettreAchatSticker(e) {
    e.preventDefault();
    const btn      = document.getElementById('btn-soumettre-achat');
    const feedback = document.getElementById('achat-feedback');

    if (!operateurSelectionne) {
        feedback.style.display = 'block';
        feedback.style.cssText = 'display:block;padding:12px;border-radius:8px;background:#fff5f5;border:1px solid #fed7d7;color:#c53030;font-weight:600;font-size:13px;margin-top:14px;';
        feedback.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Veuillez sélectionner un opérateur Mobile Money.';
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours…';

    const form = document.getElementById('formAchatSticker');
    const data = new FormData(form);

    fetch('{{ route("admin.fne.stickers.acheter") }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        body: data
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Valider le paiement';

        if (res.success) {
            feedback.style.cssText = 'display:block;padding:12px;border-radius:8px;background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;font-weight:600;font-size:13px;margin-top:14px;';
            feedback.innerHTML = '<i class="fas fa-check-circle"></i> ' + res.message;
            // Ajouter la ligne dans le tableau sans rechargement
            ajouterLigneTransaction(res.transaction);
            // Fermer le modal après 2s
            setTimeout(() => { fermerModalSticker(); }, 2200);
        } else {
            feedback.style.cssText = 'display:block;padding:12px;border-radius:8px;background:#fff5f5;border:1px solid #fed7d7;color:#c53030;font-weight:600;font-size:13px;margin-top:14px;';
            feedback.innerHTML = '<i class="fas fa-times-circle"></i> ' + (res.message || 'Erreur inconnue.');
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Valider le paiement';
        feedback.style.cssText = 'display:block;padding:12px;border-radius:8px;background:#fff5f5;border:1px solid #fed7d7;color:#c53030;font-weight:600;font-size:13px;margin-top:14px;';
        feedback.innerHTML = '<i class="fas fa-times-circle"></i> Erreur de connexion. Vérifiez votre réseau.';
    });
}

// ── Ajouter une ligne dans le tableau sans rechargement ──
function ajouterLigneTransaction(tx) {
    const tbody = document.getElementById('tbody-stickers');
    // Supprimer le message "aucun résultat" si présent
    const vide = tbody.querySelector('td[colspan]');
    if (vide) vide.closest('tr').remove();

    const opColors = {
        ORANGE: { bg:'#fff7ed', color:'#c2410c' },
        MTN:    { bg:'#fefce8', color:'#713f12' },
        MOOV:   { bg:'#eff6ff', color:'#1d4ed8' },
    };
    const oc = opColors[tx.operateur] || { bg:'var(--bg3)', color:'var(--text)' };

    const tr = document.createElement('tr');
    tr.style.animation = 'stickerFadeIn .3s ease';
    tr.innerHTML = `
        <td style="font-family:monospace;font-weight:700;color:var(--primary);">#${tx.id}</td>
        <td style="color:var(--text-3);">${tx.date}</td>
        <td><span style="display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:12px;padding:3px 10px;border-radius:20px;background:${oc.bg};color:${oc.color};">${tx.operateur}</span></td>
        <td style="font-family:monospace;">${tx.telephone}</td>
        <td style="font-weight:700;font-size:16px;color:var(--text);">${tx.nombre_stickers}</td>
        <td style="font-weight:700;">${tx.montant}</td>
        <td><span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fde68a;">${tx.statut}</span></td>
        <td style="font-family:monospace;font-size:11px;color:var(--text-3);">—</td>
    `;
    tbody.insertBefore(tr, tbody.firstChild);
}
</script>
@endsection
