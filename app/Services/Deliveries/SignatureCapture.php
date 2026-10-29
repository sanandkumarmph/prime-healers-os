<?php

namespace App\Services\Deliveries;

use Illuminate\Validation\ValidationException;

class SignatureCapture
{
    public static function clean(string $data, int $maximumBytes): string
    {
        $invalid = fn ($message) => ValidationException::withMessages(['signature_data'=>$message]);
        if (strlen($data) > (int)ceil($maximumBytes * 4 / 3) + 100
            || !preg_match('#\Adata:image/(?:png|webp);base64,([A-Za-z0-9+/]+={0,2})\z#',$data,$match)) {
            throw $invalid('Signature is invalid or too large. Clear the pad and sign again.');
        }
        $bytes=base64_decode($match[1],true); $size=$bytes !== false ? @getimagesizefromstring($bytes) : false;
        if (!$bytes || strlen($bytes)>$maximumBytes || !$size || !in_array($size[2],[IMAGETYPE_PNG,IMAGETYPE_WEBP],true)
            || $size[0]<32 || $size[1]<16 || $size[0]>8192 || $size[1]>4096 || $size[0]*$size[1]>4000000) {
            throw $invalid('Use the signature pad to capture a readable signature.');
        }
        $image=@imagecreatefromstring($bytes);
        if (!$image) throw $invalid('Signature pixels could not be decoded. Please sign again.');
        try {
            // The pad exports device pixels. Normalize high-DPI captures before
            // inspecting ink, while bounding decoded input memory above.
            $scale=min(1,1200/$size[0],600/$size[1]);
            if ($scale<1) {
                $width=max(1,(int)floor($size[0]*$scale)); $height=max(1,(int)floor($size[1]*$scale));
                $normalized=imagecreatetruecolor($width,$height);
                imagealphablending($normalized,false); imagesavealpha($normalized,true);
                imagefill($normalized,0,0,imagecolorallocatealpha($normalized,255,255,255,127));
                imagecopyresampled($normalized,$image,0,0,0,0,$width,$height,$size[0],$size[1]);
                imagedestroy($image); $image=$normalized;
                $size[0]=$width; $size[1]=$height;
            }
            $ink=0; $minX=$size[0]; $minY=$size[1]; $maxX=0; $maxY=0;
            for ($y=0;$y<$size[1];$y++) for ($x=0;$x<$size[0];$x++) {
                $color=imagecolorsforindex($image,imagecolorat($image,$x,$y));
                if ($color['alpha']<100 && $color['red']+$color['green']+$color['blue']<650) {
                    $ink++; $minX=min($minX,$x); $maxX=max($maxX,$x); $minY=min($minY,$y); $maxY=max($maxY,$y);
                }
            }
            if ($ink<20 || $ink>$size[0]*$size[1]*.6 || $maxX-$minX<8 || $maxY-$minY<2) {
                throw $invalid('The signature is blank or incomplete. Ask the recipient to sign, or record why signing was not possible.');
            }
            ob_start(); try { imagepng($image); $clean=ob_get_contents(); } finally { ob_end_clean(); }
            if (strlen($clean)>$maximumBytes) throw $invalid('Signature is too large. Clear it and sign again.');
            return $clean;
        } finally { imagedestroy($image); }
    }
}
