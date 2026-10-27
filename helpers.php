<?php
function url_https_valida($url): bool
{
    return is_string($url) &&
        filter_var($url, FILTER_VALIDATE_URL) !== false &&
        strtolower((string) parse_url($url, PHP_URL_SCHEME)) === "https";
}
