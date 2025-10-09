<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingPage extends Model
{
    protected $table = "landingpage";

    protected $fillable = [
        'section1_title',
        'section1_description',
        'section1_image',
        'section1_link',
        'section2_title',
        'section2_description',
        'section2_image1',
        'section2_image2',
        'section2_image3',
        'section3_title',
        'section3_item1_image',
        'section3_item1_title',
        'section3_item1_description',
        'section3_item2_image',
        'section3_item2_title',
        'section3_item2_description',
        'section3_item3_image',
        'section3_item3_title',
        'section3_item3_description',
        'section4_title',
        'section4_image',
        'section4_description',
        'section4_link',
        'section5_title',
        'section5_description',
        'section5_image',
        'section6_title',
        'section6_description',
        'section6_image',
    ];
    
}