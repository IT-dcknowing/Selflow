@extends('admin::gabarits.application')
@section('titre', 'Groupes d\'entreprises')
@section('topbar_titre', 'Superadmin · Groupes d\'entreprises')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-diagram-project"></i> Groupes d'entreprises</h1>
        <p>Liez les entreprises d'une même personne. Une fois le groupe activé, les administrateurs autorisés passent de l'une à l'autre depuis leur menu de profil, sans se reconnecter.</p>
    </div>
    <form method="POST" action="{{ route('superadmin.groupes.creer') }}" style="display:flex; gap:8px;">
        @csrf
        <input name="nom" class="form-control" placeholder="Nom du groupe" required style="width:220px;">
        <button class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau groupe</button>
    </form>
</div>

@forelse($groupes as $groupe)
    @php
        $idsEntreprises = $groupe->entreprises->pluck('id');
        $admins = \App\Modules\Authentification\Modeles\Utilisateur::where('role', 'admin')
            ->whereIn('entreprise_id', $idsEntreprises)->orderBy('nom')->get(['id', 'nom', 'prenom', 'email', 'entreprise_id']);
    @endphp
    <div class="card" style="padding:18px; margin-bottom:16px; border-left:4px solid {{ $groupe->actif ? '#10b981' : '#cbd5e1' }};">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
            <div>
                <strong style="font-size:16px;">{{ $groupe->nom }}</strong>
                <span style="margin-left:8px; font-size:12px; font-weight:700; color:{{ $groupe->actif ? '#047857' : '#64748b' }};">
                    {{ $groupe->actif ? 'Activé' : 'Désactivé' }}
                </span>
            </div>
            <div style="display:flex; gap:8px;">
                <form method="POST" action="{{ route('superadmin.groupes.basculer', $groupe) }}">
                    @csrf
                    <button class="btn {{ $groupe->actif ? 'btn-outline' : 'btn-primary' }} btn-sm">
                        <i class="fas {{ $groupe->actif ? 'fa-toggle-off' : 'fa-toggle-on' }}"></i> {{ $groupe->actif ? 'Désactiver' : 'Activer' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('superadmin.groupes.supprimer', $groupe) }}" onsubmit="return confirm('Supprimer ce groupe ? Les entreprises ne sont pas touchées.');">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline btn-sm"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>

        <form method="POST" action="{{ route('superadmin.groupes.enregistrer', $groupe) }}">
            @csrf
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
                <label style="font-size:13px; font-weight:700;">Nom
                    <input name="nom" class="form-control" value="{{ $groupe->nom }}" required>
                </label>
                <div></div>
                <div>
                    <div style="font-size:13px; font-weight:700; margin-bottom:6px;">Entreprises liées</div>
                    <div style="max-height:220px; overflow-y:auto; border:1px solid var(--border); border-radius:8px; padding:8px;">
                        @foreach($entreprises as $e)
                            <label style="display:flex; gap:8px; align-items:center; font-size:13px; padding:3px 0;">
                                <input type="checkbox" name="entreprises[]" value="{{ $e->id }}" @checked($idsEntreprises->contains($e->id))>
                                {{ $e->nom }} @if($e->ncc)<small style="color:var(--text-3);">· NCC {{ $e->ncc }}</small>@endif
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <div style="font-size:13px; font-weight:700; margin-bottom:6px;">Administrateurs autorisés à changer d'entreprise</div>
                    <div style="max-height:220px; overflow-y:auto; border:1px solid var(--border); border-radius:8px; padding:8px;">
                        @forelse($admins as $a)
                            <label style="display:flex; gap:8px; align-items:center; font-size:13px; padding:3px 0;">
                                <input type="checkbox" name="utilisateurs[]" value="{{ $a->id }}" @checked($groupe->utilisateurs->contains('id', $a->id))>
                                {{ $a->prenom }} {{ $a->nom }} <small style="color:var(--text-3);">· {{ $a->email }} · {{ $groupe->entreprises->firstWhere('id', $a->entreprise_id)?->nom }}</small>
                            </label>
                        @empty
                            <p style="color:var(--text-3); font-size:13px; margin:4px 0;">Enregistrez d'abord les entreprises du groupe : leurs administrateurs apparaîtront ici.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            <div style="margin-top:12px;"><button class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer</button></div>
        </form>
    </div>
@empty
    <div class="card" style="padding:24px; text-align:center; color:var(--text-3);">Aucun groupe. Créez-en un pour lier les entreprises d'une même personne.</div>
@endforelse
@endsection
