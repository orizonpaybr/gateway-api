<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Traits\IPManagementTrait;
use App\Traits\PinManagementTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{

    public function index(Request $request)
    {
        $setting = \App\Models\App::first();
        $user = auth()->user();
        
        // Calcular taxas para exibição (prioridade: usuário > global)
        $taxas = \App\Helpers\TaxaDisplayHelper::getTaxasParaExibicao($user, $setting);
        
        return view('profile.perfil', compact('setting', 'taxas'));
    }
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        
        // Atualizar campos permitidos
        $user->update($request->only([
            'name', 'email', 'telefone', 'data_nascimento',
            'cep', 'rua', 'numero_residencia', 'complemento',
            'bairro', 'cidade', 'estado'
        ]));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $user->save();
        }

        return Redirect::route('profile.index')->with('success', 'Perfil atualizado com sucesso!');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    public function uploadAvatar(Request $request)
    {
        $user = auth()->user();
        
        // Validação rigorosa do arquivo
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png,gif|max:2048'
        ]);
        
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            
            // Validação adicional de segurança
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            $extension = strtolower($file->getClientOriginalExtension());
            
            if (!in_array($extension, $allowedExtensions)) {
                return redirect()->back()->with('error', 'Tipo de arquivo não permitido. Use apenas JPG, PNG ou GIF.');
            }
            
            // Verificar se não é um arquivo PHP disfarçado
            $mimeType = $file->getMimeType();
            $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            
            if (!in_array($mimeType, $allowedMimes)) {
                return redirect()->back()->with('error', 'Arquivo inválido detectado.');
            }
            
            $filename = uniqid() . '.' . $extension;
            $destination = public_path('uploads/avatars');
            
            if (!file_exists($destination)) {
                mkdir($destination, 0755, true);
            }
            
            if ($file->move($destination, $filename)) {
                $user->avatar = '/uploads/avatars/' . $filename;
                $user->save();
                return redirect()->back()->with('success', 'Avatar atualizado com sucesso!');
            } else {
                return redirect()->back()->with('error', 'Erro ao salvar o arquivo.');
            }
        } else {
            return redirect()->back()->with('error', 'Não foi possível alterar o avatar. Tente novamente!');
        }
    }

    /**
     * Adiciona um IP à lista de permitidos
     */
    public function addAllowedIP(Request $request)
    {
        $request->validate([
            'ip' => 'required|string|max:45'
        ]);

        $user = Auth::user();
        $ip = $request->ip;

        // Validar formato do IP
        if (!IPManagementTrait::isValidIP($ip)) {
            return redirect()->back()->with('error', 'Formato de IP inválido. Use formato: 192.168.1.1, 192.168.1.0/24 ou 192.168.1.*');
        }

        if (IPManagementTrait::addAllowedIP($user, $ip)) {
            return redirect()->back()->with('success', 'IP adicionado com sucesso!');
        } else {
            return redirect()->back()->with('error', 'IP já existe na lista ou erro ao adicionar.');
        }
    }

    /**
     * Remove um IP da lista de permitidos
     */
    public function removeAllowedIP(Request $request)
    {
        $request->validate([
            'ip' => 'required|string|max:45'
        ]);

        $user = Auth::user();
        $ip = $request->ip;

        if (IPManagementTrait::removeAllowedIP($user, $ip)) {
            return redirect()->back()->with('success', 'IP removido com sucesso!');
        } else {
            return redirect()->back()->with('error', 'Erro ao remover IP.');
        }
    }

    /**
     * Lista IPs permitidos (API)
     */
    public function getAllowedIPs()
    {
        $user = Auth::user();
        $ips = IPManagementTrait::getAllowedIPs($user);
        
        return response()->json([
            'success' => true,
            'ips' => $ips
        ]);
    }

    /**
     * Cria um PIN para o usuário
     */
    public function createPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|size:6|regex:/^\d{6}$/'
        ]);

        $user = Auth::user();
        $result = PinManagementTrait::createPin($user, $request->pin);

        if ($result['success']) {
            return redirect()->back()->with('success', $result['message'])->with('pin_created', $result['pin']);
        } else {
            return redirect()->back()->with('error', $result['message']);
        }
    }

    /**
     * Altera o PIN do usuário
     */
    public function changePin(Request $request)
    {
        $request->validate([
            'current_pin' => 'required|string|size:6|regex:/^\d{6}$/',
            'new_pin' => 'required|string|size:6|regex:/^\d{6}$/'
        ]);

        $user = Auth::user();
        $result = PinManagementTrait::changePin($user, $request->current_pin, $request->new_pin);

        if ($result['success']) {
            return redirect()->back()->with('success', $result['message']);
        } else {
            return redirect()->back()->with('error', $result['message']);
        }
    }

    /**
     * Ativa/Desativa o PIN
     */
    public function togglePin(Request $request)
    {
        $request->validate([
            'active' => 'required|boolean'
        ]);

        $user = Auth::user();
        $result = PinManagementTrait::togglePinStatus($user, $request->active);

        if ($result['success']) {
            return redirect()->back()->with('success', $result['message']);
        } else {
            return redirect()->back()->with('error', $result['message']);
        }
    }

    /**
     * Remove o PIN do usuário
     */
    public function removePin(Request $request)
    {
        $request->validate([
            'current_pin' => 'required|string|size:6|regex:/^\d{6}$/'
        ]);

        $user = Auth::user();
        $result = PinManagementTrait::removePin($user, $request->current_pin);

        if ($result['success']) {
            return redirect()->back()->with('success', $result['message']);
        } else {
            return redirect()->back()->with('error', $result['message']);
        }
    }

    /**
     * Verifica PIN (API)
     */
    public function verifyPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|size:6|regex:/^\d{6}$/'
        ]);

        $user = Auth::user();
        $isValid = PinManagementTrait::verifyPin($user, $request->pin);

        return response()->json([
            'success' => $isValid,
            'message' => $isValid ? 'PIN válido' : 'PIN inválido'
        ]);
    }
}
