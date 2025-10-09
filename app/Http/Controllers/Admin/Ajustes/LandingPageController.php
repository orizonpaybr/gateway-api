<?php

namespace App\Http\Controllers\Admin\Ajustes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\LandingPage;

class LandingPageController extends Controller
{
    public function welcome()
    {
        $landing = LandingPage::first();
        return view("welcome", compact('landing'));
    }

    public function index()
    {
        $landing = LandingPage::first();
        return view("admin.ajustes.landingpage", compact('landing'));
    }

    public function update(Request $request)
{
    $setting = LandingPage::firstOrFail(); // ou create([]) caso queira criar se não existir

        $data = $request->except('_token', '_method');

        // Campos de imagem (nome => coluna)
        $imageFields = [
            'section1_image',
            'section2_image1',
            'section2_image2',
            'section2_image3',
            'section3_item1_image',
            'section3_item2_image',
            'section3_item3_image',
            'section4_image',
            'section5_image',
            'section6_image',
        ];

        foreach ($imageFields as $field) {
            if ($request->hasFile($field)) {
              // Remove imagem antiga se houver
              if ($setting->$field && Storage::disk('public')->exists(str_replace('/storage/', '', $setting->$field))) {
                  Storage::disk('public')->delete(str_replace('/storage/', '', $setting->$field));
              }

              $destination = public_path('landing');
              if (!file_exists($destination)) {
                  mkdir($destination, 0775, true);
			}
              
              $file = $request->file($field);
              $filename = uniqid() . '.' . $file->getClientOriginalExtension();
              $file->move($destination, $filename);
              $data[$field] = '/landing/' . $filename;

          } else {
              unset($data[$field]);
          }
        }

        $setting->update($data);

        return redirect()->back()->with('success', 'Landing page atualizada com sucesso!');
}

}