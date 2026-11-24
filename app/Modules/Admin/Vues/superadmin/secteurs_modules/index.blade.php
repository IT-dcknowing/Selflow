@extends('admin::gabarits.application')

@section('titre', 'Supervision - Secteurs & Modules')
@section('topbar_titre', 'Supervision · Secteurs &amp; Modules')

@section('styles')
<style>
/* ── Page Secteurs & Modules (Respect de la charte de l'application) ── */

/* Légende des modules */
.modules-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
    padding: 12px 16px;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 8px;
}
.legend-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 600;
    background: rgba(0, 43, 92, 0.05);
    color: var(--primary);
    border: 1px solid rgba(0, 43, 92, 0.1);
    padding: 4px 8px;
    border-radius: 4px;
}
.legend-badge i {
    font-size: 12px;
}

/* Actions globales */
.actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}
.actions-bar-left {
    display: flex;
    align-items: center;
    gap: 8px;
}
.actions-bar-hint {
    font-size: 12.5px;
    color: var(--text-3);
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Custom Checkbox (S'harmonise avec le bleu de l'application) */
.custom-cb-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 2px solid var(--border);
    background: #fff;
    cursor: pointer;
    transition: all 0.15s ease;
    color: transparent;
}
.custom-cb-wrapper input[type=checkbox] {
    display: none;
}
.custom-cb-wrapper i {
    font-size: 12px;
    transition: all 0.15s ease;
}
.custom-cb-wrapper.checked {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}
.custom-cb-wrapper:hover:not(.checked) {
    border-color: var(--primary);
    background: rgba(26, 115, 232, 0.06);
}

/* En-tête des colonnes modules */
.module-th-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}
.module-th-content i {
    font-size: 14px;
    color: var(--primary);
}
.module-th-content span {
    font-size: 10px;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    font-weight: 700;
    text-align: center;
}

/* Boutons de contrôle rapide (colonnes) */
.col-control-btns {
    display: flex;
    justify-content: center;
    gap: 4px;
    margin-top: 6px;
}
.btn-col-action {
    width: 20px;
    height: 20px;
    border-radius: 4px;
    border: 1px solid var(--border);
    background: #fff;
    font-size: 9px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--text-2);
    transition: all 0.15s;
}
.btn-col-action:hover {
    background: var(--bg2);
    border-color: var(--primary);
    color: var(--primary);
}

/* Ligne par défaut */
.row-default {
    background: rgba(26, 115, 232, 0.03) !important;
}

/* Badges secteurs */
.secteur-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    border-radius: 4px;
    font-weight: 700;
    font-size: 12px;
}

/* Footer d'action */
.form-footer {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
</style>
@endsection

@section('contenu')
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-cubes"></i> Secteurs &amp; Modules</h1>
        <p>Associez les modules activés par défaut lors de la création d'entreprises selon leur secteur d'activité principal</p>
    </div>
    <button form="form-secteurs-modules" type="submit" class="btn btn-primary">
        <i class="fas fa-floppy-disk"></i> Sauvegarder la configuration
    </button>
</div>

@if(session('succes'))
<div class="alert alert-success" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:20px;color:#065f46;font-weight:500;">
    <i class="fas fa-check-circle" style="font-size:16px;color:#10b981;"></i>
    {{ session('succes') }}
</div>
@endif

{{-- ── LEGENDE DES MODULES ── --}}
<div class="modules-legend">
    <div style="font-size: 11px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; margin-right: 8px;">
        Modules disponibles :
    </div>
    @foreach($modules as $cle => $module)
        <div class="legend-badge">
            <i class="fas {{ $module['icone'] }}"></i>
            <span>{{ $module['label'] }}</span>
        </div>
    @endforeach
</div>

<form id="form-secteurs-modules" action="{{ route('superadmin.secteurs_modules.sauvegarder') }}" method="POST">
    @csrf

    {{-- ── BARRE D'ACTIONS GLOBALES ── --}}
    <div class="actions-bar">
        <div class="actions-bar-left">
            <button type="button" class="btn btn-outline btn-sm" onclick="toutCocher()">
                <i class="fas fa-check-square"></i> Tout cocher
            </button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toutDecocher()">
                <i class="fas fa-square"></i> Tout décocher
            </button>
        </div>
        <div class="actions-bar-hint">
            <i class="fas fa-info-circle" style="color: var(--primary);"></i>
            Cochez les modules pour les activer par défaut à la création de l'entreprise.
        </div>
    </div>

    {{-- ── GRILLE DE CONFIGURATION ── --}}
    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Secteur d'activité</th>
                        @foreach($modules as $cle => $module)
                            <th style="text-align: center; min-width: 100px;">
                                <div class="module-th-content" title="{{ $module['label'] }}">
                                    <i class="fas {{ $module['icone'] }}"></i>
                                    <span>{{ Str::words($module['label'], 2, '') }}</span>
                                </div>
                                <div class="col-control-btns">
                                    <button type="button" class="btn-col-action" onclick="cocherCol('{{ $cle }}', true)" title="Tout cocher pour ce module">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn-col-action" onclick="cocherCol('{{ $cle }}', false)" title="Tout décocher pour ce module">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </th>
                        @endforeach
                        <th style="text-align: center; width: 130px;">Actions Ligne</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Ligne par défaut --}}
                    @php $cSecteur = '__defaut__'; @endphp
                    <tr class="row-default">
                        <td style="font-weight: 700;">
                            <span class="secteur-badge" style="background: rgba(148, 163, 184, 0.12); color: var(--text-1); border: 1px solid rgba(148, 163, 184, 0.2);">
                                <i class="fas fa-globe" style="color: #94a3b8; margin-right: 6px;"></i>
                                Par défaut <small style="margin-left: 4px;">(aucun secteur)</small>
                            </span>
                        </td>
                        @foreach($modules as $cle => $module)
                            @php $checked = in_array($cle, $config[$cSecteur] ?? array_keys($modules)); @endphp
                            <td style="text-align: center;">
                                <label class="custom-cb-wrapper {{ $checked ? 'checked' : '' }}">
                                    <input type="checkbox" name="modules___defaut__[]" value="{{ $cle }}"
                                        data-secteur="{{ $cSecteur }}" data-module="{{ $cle }}"
                                        {{ $checked ? 'checked' : '' }} onchange="syncUI(this)">
                                    <i class="fas fa-check"></i>
                                </label>
                            </td>
                        @endforeach
                        <td style="text-align: center;">
                            <button type="button" class="btn btn-outline btn-xs" onclick="cocherLigne('{{ $cSecteur }}', true)" style="font-size: 11px; padding: 2px 6px;">Tout</button>
                            <button type="button" class="btn btn-outline btn-xs" onclick="cocherLigne('{{ $cSecteur }}', false)" style="font-size: 11px; padding: 2px 6px; color: var(--danger); border-color: rgba(239, 68, 68, 0.2);">Aucun</button>
                        </td>
                    </tr>

                    {{-- Une ligne par secteur --}}
                    @foreach($secteurs as $nom => $info)
                        @php
                            $cSecteur = str_replace(['/', ' '], '_', $nom);
                            $mods     = $config[$nom] ?? array_keys($modules);
                        @endphp
                        <tr>
                            <td style="font-weight: 700;">
                                <span class="secteur-badge" style="background: {{ $info['couleur'] }}0e; color: {{ $info['couleur'] }}; border: 1px solid {{ $info['couleur'] }}30;">
                                    <i class="fas {{ $info['icone'] }}" style="margin-right: 6px;"></i>
                                    {{ $nom }}
                                </span>
                            </td>
                            @foreach($modules as $cle => $module)
                                @php $checked = in_array($cle, $mods); @endphp
                                <td style="text-align: center;">
                                    <label class="custom-cb-wrapper {{ $checked ? 'checked' : '' }}">
                                        <input type="checkbox" name="modules_{{ $cSecteur }}[]" value="{{ $cle }}"
                                            data-secteur="{{ $cSecteur }}" data-module="{{ $cle }}"
                                            {{ $checked ? 'checked' : '' }} onchange="syncUI(this)">
                                        <i class="fas fa-check"></i>
                                    </label>
                                </td>
                            @endforeach
                            <td style="text-align: center;">
                                <button type="button" class="btn btn-outline btn-xs" onclick="cocherLigne('{{ $cSecteur }}', true)" style="font-size: 11px; padding: 2px 6px;">Tout</button>
                                <button type="button" class="btn btn-outline btn-xs" onclick="cocherLigne('{{ $cSecteur }}', false)" style="font-size: 11px; padding: 2px 6px; color: var(--danger); border-color: rgba(239, 68, 68, 0.2);">Aucun</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── FOOTER DE L'ACTION ── --}}
    <div class="form-footer">
        <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
            <i class="fas fa-floppy-disk"></i> Sauvegarder la configuration
        </button>
    </div>
</form>
</div>
@endsection

@section('scripts')
<script>
function syncUI(input) {
    input.closest('.custom-cb-wrapper').classList.toggle('checked', input.checked);
}

function cocherCol(module, etat) {
    document.querySelectorAll(`input[data-module="${module}"]`).forEach(inp => {
        inp.checked = etat;
        syncUI(inp);
    });
}

function cocherLigne(secteur, etat) {
    document.querySelectorAll(`input[data-secteur="${secteur}"]`).forEach(inp => {
        inp.checked = etat;
        syncUI(inp);
    });
}

function toutCocher() {
    document.querySelectorAll('.custom-cb-wrapper input').forEach(inp => {
        inp.checked = true;
        syncUI(inp);
    });
}

function toutDecocher() {
    document.querySelectorAll('.custom-cb-wrapper input').forEach(inp => {
        inp.checked = false;
        syncUI(inp);
    });
}
</script>
@endsection
