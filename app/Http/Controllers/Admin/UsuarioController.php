<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UsuarioController extends Controller {
    public function index(): View {
        return view('usuarios.index', [
            'usuarios' => User::with('roles')->orderBy('name')->get(),
        ]);
    }

    public function create(): View {
        return view('usuarios.form', ['usuario' => new User(['is_active' => true]), 'roles' => Role::all()]);
    }

    public function store(UsuarioRequest $request): RedirectResponse {
        DB::transaction(function () use ($request) {
            $usuario = User::create($request->safe()->only(['name', 'email', 'password']) + [
                'is_active' => $request->boolean('is_active'),
            ]);
            $usuario->asignarRol($request->validated('rol'));
        });

        return redirect()->route('usuarios.index')->with('ok', 'Usuario creado.');
    }

    public function edit(User $usuario): View {
        return view('usuarios.form', ['usuario' => $usuario, 'roles' => Role::all()]);
    }

    public function update(UsuarioRequest $request, User $usuario): RedirectResponse {
        if ($usuario->is($request->user()) && ($request->validated('rol') !== Role::ADMIN || ! $request->boolean('is_active'))) {
            return back()->withInput()->withErrors(['rol' => 'No puedes quitarte el rol de administrador ni desactivarte.']);
        }

        DB::transaction(function () use ($request, $usuario) {
            $datos = $request->safe()->only(['name', 'email']) + ['is_active' => $request->boolean('is_active')];
            if ($request->filled('password')) {
                $datos['password'] = $request->validated('password');
            }
            $usuario->update($datos);
            $usuario->asignarRol($request->validated('rol'));
        });

        return redirect()->route('usuarios.index')->with('ok', 'Usuario actualizado.');
    }
}
