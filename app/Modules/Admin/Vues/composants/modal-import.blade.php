{{--
    Composant modal Import CSV réutilisable
    Paramètres :
      $type   — 'points-de-vente' | 'clients' | 'fournisseurs' | 'utilisateurs' | 'produits'
      $label  — Libellé affiché (ex: "Points de vente")
      $id     — ID unique du modal (ex: "modalImportPdv")
--}}
@php
    $modalId      = $id ?? ('modalImport' . Str::studly($type));
    $routeExemple = route('admin.import.exemple', ['type' => $type]);
    $routePreview = route('admin.import.preview', ['type' => $type]);
    $routeImport  = route('admin.import.importer', ['type' => $type]);
@endphp

{{-- Boutons déclencheurs à placer dans la page parente --}}
<style>
    #{{ $modalId }} .import-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1100; align-items:center; justify-content:center; }
    #{{ $modalId }} .import-overlay.open { display:flex; }
    #{{ $modalId }} .import-box { background:#fff; border-radius:16px; padding:28px; max-width:640px; width:100%; max-height:85vh; overflow-y:auto; box-shadow:0 25px 60px rgba(0,0,0,.2); animation:importFadeIn .2s ease; }
    @keyframes importFadeIn { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }

    #{{ $modalId }} .drop-zone {
        border:2px dashed var(--border); border-radius:12px; padding:36px; text-align:center;
        cursor:pointer; transition:all .15s; background:var(--bg3);
    }
    #{{ $modalId }} .drop-zone.dragover { border-color:var(--primary); background:#eff6ff; }
    #{{ $modalId }} .drop-zone i { font-size:40px; color:var(--text-3); margin-bottom:10px; display:block; }
    #{{ $modalId }} .drop-zone p { margin:0; color:var(--text-3); font-size:13px; }
    #{{ $modalId }} .drop-zone strong { color:var(--primary); }

    #{{ $modalId }} .preview-table { width:100%; border-collapse:collapse; font-size:12px; }
    #{{ $modalId }} .preview-table th { background:#f8fafc; padding:6px 10px; border:1px solid var(--border); font-weight:700; color:var(--text-3); text-align:left; white-space:nowrap; }
    #{{ $modalId }} .preview-table td { padding:6px 10px; border:1px solid var(--border); }

    #{{ $modalId }} .erreur-item { padding:6px 10px; background:#fff5f5; border-left:3px solid #ef4444; border-radius:4px; font-size:12px; color:#c53030; margin-bottom:4px; }
</style>

<div id="{{ $modalId }}">
    <div class="import-overlay" id="{{ $modalId }}-overlay">
        <div class="import-box">
            {{-- En-tête --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                <h3 style="margin:0;font-size:17px;font-weight:800;color:var(--text-1);">
                    <i class="fas fa-file-import" style="color:var(--primary);margin-right:8px;"></i>Importer — {{ $label }}
                </h3>
                <button onclick="fermerImport('{{ $modalId }}')" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--text-3);">✕</button>
            </div>

            {{-- Info + lien exemple --}}
            <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#eff6ff;border-radius:10px;border:1px solid #bfdbfe;margin-bottom:18px;">
                <i class="fas fa-info-circle" style="color:#1d4ed8;font-size:16px;flex-shrink:0;"></i>
                <div style="font-size:13px;color:#1e40af;line-height:1.4;">
                    Téléchargez le fichier modèle à compléter :
                    <a href="{{ $routeExemple }}" style="font-weight:700;text-decoration:underline;margin:0 4px;" download>
                        <i class="fas fa-download"></i> Modèle CSV
                    </a>
                    ou 
                    <a href="{{ $routeExemple }}?format=excel" style="font-weight:700;text-decoration:underline;margin-left:4px;" download>
                        <i class="fas fa-file-excel"></i> Modèle Excel (.xlsx)
                    </a>
                </div>
            </div>

            {{-- Zone drag & drop --}}
            <div class="drop-zone" id="{{ $modalId }}-dropzone"
                onclick="document.getElementById('{{ $modalId }}-file').click()"
                ondragover="event.preventDefault();this.classList.add('dragover')"
                ondragleave="this.classList.remove('dragover')"
                ondrop="gererDrop(event, '{{ $modalId }}', '{{ $routePreview }}')">
                <i class="fas fa-cloud-upload-alt"></i>
                <p><strong>Glissez votre fichier CSV ou Excel ici</strong> ou cliquez pour choisir</p>
                <p style="margin-top:6px;font-size:11px;">Formats acceptés : CSV (séparateur ; ou ,) — Excel (.xlsx, .xls) — Max 5 Mo</p>
            </div>
            <input type="file" id="{{ $modalId }}-file" accept=".csv,.txt,.xlsx,.xls" style="display:none"
                onchange="previsualiserFichier(this, '{{ $modalId }}', '{{ $routePreview }}')">

            {{-- Nom du fichier sélectionné --}}
            <div id="{{ $modalId }}-nom-fichier" style="display:none;margin-top:10px;font-size:12px;color:var(--text-3);padding:8px 12px;background:var(--bg2);border-radius:8px;">
                <i class="fas fa-file-csv"></i> <span id="{{ $modalId }}-nom-fichier-texte"></span>
            </div>

            {{-- Prévisualisation --}}
            <div id="{{ $modalId }}-preview-section" style="display:none;margin-top:18px;">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-3);letter-spacing:.4px;margin-bottom:8px;">
                    <i class="fas fa-table"></i> Prévisualisation — 5 premières lignes
                    <span id="{{ $modalId }}-total-lignes" style="font-weight:400;margin-left:8px;"></span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="preview-table" id="{{ $modalId }}-preview-table">
                        <thead id="{{ $modalId }}-preview-head"></thead>
                        <tbody id="{{ $modalId }}-preview-body"></tbody>
                    </table>
                </div>
            </div>

            {{-- Rapport d'erreurs --}}
            <div id="{{ $modalId }}-rapport" style="display:none;margin-top:16px;">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-3);letter-spacing:.4px;margin-bottom:8px;">
                    <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Rapport d'import
                </div>
                <div id="{{ $modalId }}-rapport-content"></div>
            </div>

            {{-- Boutons action --}}
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:22px;">
                <button type="button" class="btn btn-outline" onclick="fermerImport('{{ $modalId }}')">Annuler</button>
                <button type="button" class="btn btn-primary" id="{{ $modalId }}-btn-import"
                    style="display:none;"
                    onclick="lancerImport('{{ $modalId }}', '{{ $routeImport }}', '{{ csrf_token() }}')">
                    <i class="fas fa-upload"></i> Lancer l'import
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Helpers partagés Import Modal ──────────────────────────────────
function ouvrirImport(id) {
    document.getElementById(id + '-overlay').classList.add('open');
}
function fermerImport(id) {
    document.getElementById(id + '-overlay').classList.remove('open');
    // Reset état
    document.getElementById(id + '-preview-section').style.display = 'none';
    document.getElementById(id + '-rapport').style.display = 'none';
    document.getElementById(id + '-btn-import').style.display = 'none';
    document.getElementById(id + '-nom-fichier').style.display = 'none';
    document.getElementById(id + '-file').value = '';
    document.getElementById(id + '-dropzone').classList.remove('dragover');
}

function gererDrop(event, id, routePreview) {
    event.preventDefault();
    event.currentTarget.classList.remove('dragover');
    const file = event.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById(id + '-file');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    previsualiser(id, file, routePreview);
}

function previsualiserFichier(input, id, routePreview) {
    if (!input.files[0]) return;
    previsualiser(id, input.files[0], routePreview);
}

function previsualiser(id, file, routePreview) {
    document.getElementById(id + '-nom-fichier').style.display = 'block';
    document.getElementById(id + '-nom-fichier-texte').textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' Ko)';
    document.getElementById(id + '-preview-section').style.display = 'none';
    document.getElementById(id + '-rapport').style.display = 'none';

    const formData = new FormData();
    formData.append('fichier', file);
    formData.append('_token', document.querySelector('meta[name=csrf-token]')?.content || '');

    fetch(routePreview, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                afficherErreurImport(id, res.message);
                return;
            }
            // En-têtes
            const thead = document.getElementById(id + '-preview-head');
            thead.innerHTML = '<tr>' + res.entetes.map(h => `<th>${h}</th>`).join('') + '</tr>';
            // Lignes
            const tbody = document.getElementById(id + '-preview-body');
            tbody.innerHTML = res.lignes.map(row =>
                '<tr>' + row.map(cell => `<td>${cell}</td>`).join('') + '</tr>'
            ).join('');
            // Total
            document.getElementById(id + '-total-lignes').textContent =
                '(' + res.total + ' ligne(s) au total à importer)';
            document.getElementById(id + '-preview-section').style.display = 'block';
            document.getElementById(id + '-btn-import').style.display = 'inline-flex';
        })
        .catch(() => afficherErreurImport(id, 'Erreur réseau lors de la prévisualisation.'));
}

function afficherErreurImport(id, msg) {
    const rapport = document.getElementById(id + '-rapport');
    document.getElementById(id + '-rapport-content').innerHTML =
        '<div class="erreur-item"><i class="fas fa-times-circle"></i> ' + msg + '</div>';
    rapport.style.display = 'block';
}

function lancerImport(id, routeImport, token) {
    const btn   = document.getElementById(id + '-btn-import');
    const file  = document.getElementById(id + '-file').files[0];
    if (!file) return;

    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Import en cours…';

    const formData = new FormData();
    formData.append('fichier', file);
    formData.append('_token', token);

    fetch(routeImport, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> Lancer l\'import';

            const rapport = document.getElementById(id + '-rapport');
            let html = '';

            // Vert si quelque chose est entré, rouge si tout a été refusé :
            // « 0 ligne importée avec succès » s'affichait en vert.
            if (res.message) {
                const ok = res.success;
                html += `<div style="padding:12px 16px;background:${ok ? '#d1fae5' : '#fee2e2'};border-radius:8px;border:1px solid ${ok ? '#6ee7b7' : '#fca5a5'};color:${ok ? '#065f46' : '#991b1b'};font-weight:600;font-size:13px;margin-bottom:10px;">
                    <i class="fas ${ok ? 'fa-check-circle' : 'fa-circle-exclamation'}"></i> ${res.message}
                </div>`;
            }

            if (res.erreurs && res.erreurs.length > 0) {
                html += '<div style="margin-top:8px;">';
                res.erreurs.forEach(e => {
                    html += `<div class="erreur-item"><i class="fas fa-exclamation-circle"></i> ${e}</div>`;
                });
                html += '</div>';
            }

            document.getElementById(id + '-rapport-content').innerHTML = html;
            rapport.style.display = 'block';

            // Recharger la page après 2s si import réussi
            if (res.success && res.importes > 0) {
                setTimeout(() => window.location.reload(), 2200);
            }
        })
        .catch(() => {
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-upload"></i> Lancer l\'import';
            afficherErreurImport(id, 'Erreur réseau lors de l\'import.');
        });
}

// Fermer en cliquant sur l'overlay
document.getElementById('{{ $modalId }}-overlay')?.addEventListener('click', function(e) {
    if (e.target === this) fermerImport('{{ $modalId }}');
});
</script>
