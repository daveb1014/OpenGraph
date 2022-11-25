<?php

namespace shweshi\OpenGraph;

use DOMDocument;
use shweshi\OpenGraph\Exceptions\FetchException;

class OpenGraph
{
	public function fetch($url, $allMeta = null, $lang = null, $options = LIBXML_NOWARNING | LIBXML_NOERROR, $userAgent = 'Curl')
	{
		$html = $this->curl_get_contents($url, $lang, $userAgent);

		/**
		 * parsing starts here:.
		 */
		$doc = new DOMDocument();

		$libxml_previous_state = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, $options);
		//catch possible errors due to empty or malformed HTML
		if ($options > 0 && ($options & (LIBXML_NOWARNING | LIBXML_NOERROR)) == 0) {
			\Log::warning(libxml_get_errors());
		}
		libxml_clear_errors();
		// restore previous state
		libxml_use_internal_errors($libxml_previous_state);

		$tags = $doc->getElementsByTagName('meta');
		$metadata = [];
		foreach ($tags as $tag) {
			$metaproperty = ($tag->hasAttribute('property')) ? $tag->getAttribute('property') : $tag->getAttribute('name');
			if (!$allMeta && $metaproperty && strpos($tag->getAttribute('property'), 'og:') === 0) {
				$key = strtr(substr($metaproperty, 3), '-', '_');
				$value = $this->get_meta_value($tag);
			}
			if ($allMeta && $metaproperty) {
				$key = (strpos($metaproperty, 'og:') === 0) ? strtr(substr($metaproperty, 3), '-', '_') : $metaproperty;
				$value = $this->get_meta_value($tag);
			}
			if (!empty($key)) {
				$metadata[$key] = $value;
			}
			/*
			 * Verify image url
			 */
			if (isset($metadata['image'])) {
				$isValidImageUrl = $this->verify_image_url($metadata['image']);
				if (!$isValidImageUrl) {
					$metadata['image'] = '';
				}
			}
		}

		return $metadata;
	}

	protected function curl_get_contents($url, $lang, $userAgent)
	{
		$headers = [
			'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Cache-Control: no-cache',
			'User-Agent: '.$userAgent,
		];

		if ($lang) {
			array_push($headers, 'Accept-Language: '.$lang);
		}

		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL            => $url,
			CURLOPT_FAILONERROR    => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_ENCODING       => 'UTF-8',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'GET',
			CURLOPT_HTTPHEADER     => $headers,
		]);

		$response = curl_exec($curl);
		if (curl_errno(/** @scrutinizer ignore-type */ $curl) !== 0) {
			throw new FetchException(curl_error(/** @scrutinizer ignore-type */ $curl), curl_errno($curl), null, curl_getinfo(/** @scrutinizer ignore-type */ $curl));
		}

		curl_close(/** @scrutinizer ignore-type */ $curl);

		return $response;
	}

	protected function verify_image_url($url)
	{
		$path = parse_url($url, PHP_URL_PATH);
		$encoded_path = array_map('urlencode', explode('/', $path));
		$url = str_replace($path, implode('/', $encoded_path), $url);
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}
		try {
			/*$context_headers = stream_context_create([
				'ssl' => [
					'verify_peer'      => false,
					'verify_peer_name' => false,
				],
			]);
			$headers = get_headers($url, true, $context_headers);*/

			// DB 25112022 curl alternative that doesn't require allow_url_fopen
			// https://stackoverflow.com/a/6087279/4360876
			/*$curl = curl_init();
			curl_setopt_array($curl, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_URL => 'https://www1.racgp.org.au/getattachment/f6ff333d-f50d-4f59-b6c8-74e9dbc82dd1/October-2022-Budget-in-detail-The-impact-on-genera.aspx']);
			curl_exec($curl);
			$response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close($curl);*/

			// DB 25112022 the laravel way
			// https://laravel.com/docs/9.x/http-client#making-requests
//            return stripos($headers[0], '200 OK') ? true : false;
			return \Http::get($url)->status() == 200;
		} catch (\Exception $e) {
			\Log::error($e);
			return false;
		}
	}

	protected function get_meta_value($tag)
	{
		if (!empty($tag->getAttribute('content'))) {
			$value = $tag->getAttribute('content');
		} elseif (!empty($tag->getAttribute('value'))) {
			$value = $tag->getAttribute('value');
		} else {
			$value = '';
		}

		return $value;
	}
}
