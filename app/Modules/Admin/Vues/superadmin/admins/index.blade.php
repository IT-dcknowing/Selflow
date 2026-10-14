@extends('admin::gabarits.application')

@section('titre', 'Admins Internes - Administration')
@section('topbar_titre', 'Administration Interne — SuperAdmins')

@section('contenu')
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-users-shield"></i> Administration Interne</h1>
        <p>Gérez les comptes d'administration globale de la plateforme, leurs habilitations d'accès et leurs statuts.</p>
    </div>
    @if(auth()->user()->aHabilitation('administration_interne'))
    <a href="{{ route('superadmin.admins.creer') }}" class="btn btn-primary">
        <i class="fas fa-user-plus"></i> Nouvel Administrateur
    </a>
    @endif
</div>

@if(session('succes'))
<div class="alert alert-success" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:20px;color:#065f46;font-weight:500;">
    <i class="fas fa-check-circle" style="font-size:16px;color:#10b981;"></i>
    {{ session('succes') }}
</div>
@endif

@if(session('erreur'))
<div class="alert alert-danger" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;margin-bottom:20px;color:#991b1b;font-weight:500;">
    <i class="fas fa-times-circle" style="font-size:16px;color:#ef4444;"></i>
    {{ session('erreur') }}
</div>
@endif

{{-- ── BARRE DE RECHERCHE ── --}}
<div class="card" style="padding: 20px; margin-bottom: 20px; background: var(--bg2); border: 1px solid var(--border);">
    <form method="GET" action="{{ route('superadmin.admins.index') }}" style="display: flex; gap: 12px; align-items: center; max-width: 500px;" id="formRechercheAdmins">
        <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Rechercher par nom, prénom ou e-mail..." style="border-radius: 8px; flex: 1;"
               title="Appuyez sur Entrée pour lancer la recherche" onkeydown="if (event.key === 'Enter') { event.preventDefault(); document.getElementById('formRechercheAdmins').submit(); }">
        <button type="submit" class="btn btn-primary" style="padding: 10px 16px; font-weight: 600; border-radius: 8px;">
            <i class="fas fa-search"></i>
        </button>
        @if(request()->filled('search'))
            <a href="{{ route('superadmin.admins.index') }}" class="btn btn-outline" style="padding: 10px 14px; border-radius: 8px;">
                <i class="fas fa-times"></i>
            </a>
        @endif
    </form>
</div>

{{-- ── TABLEAU DES SUPERADMINS ── --}}
<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Utilisateur</th>
                    <th>E-mail</th>
                    <th>Habilitations / Droits d'administration</th>
                    <th>Statut</th>
                    <th style="text-align: right; width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($admins as $admin)
                <tr>
                    <td>
                        <div style="font-weight: 700; color: var(--text-1); font-size: 14px;">
                            {{ $admin->prenom }} {{ $admin->nom }}
                        </div>
                        @if($admin->email === 'superadmin@gmail.com')
                            <span class="badge badge-purple" style="font-size: 10px; margin-top: 4px; font-weight: 700;">PROPRIÉTAIRE</span>
                        @else
                            <span class="badge" style="background:#e0f2fe; color:#0369a1; font-size: 10px; margin-top: 4px; font-weight: 700;">SUPERADMIN</span>
                        @endif
                    </td>
                    <td style="font-family: monospace; font-size: 13px;">{{ $admin->email }}</td>
                    <td>
                        @if($admin->email === 'superadmin@gmail.com')
                            <span style="font-size: 12.5px; color: var(--success); font-weight: 700;">
                                <i class="fas fa-shield-halved"></i> Accès total irrévocable
                            </span>
                        @else
                            @php
                                $habs = $admin->habilitations ?? [];
                                $labels = [
                                    'tableau_de_bord_superadmin' => 'Tableau de bord',
                                    'gestion_entreprises'        => 'Gestion Entreprises',
                                    'gestion_comptaflow'        => 'Liaisons COMPTAFLOW',
                                    'gestion_fne'               => 'Gestion FNE',
                                    'administration_interne'    => 'Administration Interne',
                                ];
                            @endphp
                            @if(count($habs) > 0)
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    @foreach($habs as $h)
                                        @if(isset($labels[$h]))
                                            <span class="badge" style="background: rgba(108, 92, 231, 0.1); color: #6c5ce7; font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 4px;">
                                                {{ $labels[$h] }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @else
                                <span style="font-size: 12px; color: var(--text-3); font-style: italic;">Aucun droit accordé</span>
                            @endif
                        @endif
                    </td>
                    <td>
                        @if($admin->statut === 'actif')
                            <span class="badge badge-success" style="font-weight: 700; text-transform: uppercase; font-size: 10px;">Actif</span>
                        @else
                            <span class="badge badge-danger" style="font-weight: 700; text-transform: uppercase; font-size: 10px;">Inactif</span>
                        @endif
                    </td>
                    <td style="text-align: right;">
                        @if(auth()->user()->aHabilitation('administration_interne'))
                            <a href="{{ route('superadmin.admins.modifier', $admin) }}" class="btn btn-sm btn-outline" title="Modifier" style="padding: 6px 10px; border-radius: 6px;">
                                <i class="fas fa-edit"></i>
                            </a>
                            
                            @if($admin->id !== auth()->id() && $admin->email !== 'superadmin@gmail.com')
                                <form method="POST" action="{{ route('superadmin.admins.supprimer', $admin) }}" style="display: inline-block; margin-left: 5px;" onsubmit="return confirm('Confirmez-vous la suppression de cet administrateur ? Cette action est irréversible.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" title="Supprimer" style="padding: 6px 10px; border-radius: 6px; background:#ef4444; border-color:#ef4444; color:#fff;">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            @endif
                        @else
                            <span style="font-size: 12px; color: var(--text-3); font-style: italic;">Lecture seule</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-3); padding: 40px;">
                        <i class="fas fa-user-shield" style="font-size: 40px; margin-bottom: 12px; display: block; color: var(--border);"></i>
                        Aucun administrateur trouvé.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($admins->hasPages())
    <div style="padding: 16px; border-top: 1px solid var(--border);">
        {{ $admins->links() }}
    </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const champ = document.querySelector('#formRechercheAdmins input[name="search"]');
        if (champ && champ.value) {
            champ.focus();
            champ.setSelectionRange(champ.value.length, champ.value.length);
        }
    });
</script>
@endsection
