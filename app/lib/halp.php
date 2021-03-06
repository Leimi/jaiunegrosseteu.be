<?php
class Halp {
	/**
	 * Retourne l'image liée à une teube
	 *
	 * @param  Bean $teube objet teube de la base
	 * @param  boolean $dataURIFallback si aucune image est trouvée on peut choisir de retourner l'image encodée en base64 contenu dans la $teube
	 * @return string chemin ou data URI de l'image
	 */
	public static function drawing($teube, $suffix = '') {
		$file = "http://stateuic.habite.la/drawings/".$teube->id.(!empty($suffix) ? '.'.$suffix : '').('.'.$teube->modified_count).".png";
		return $file;
	}

	public static function pageURL($page) {
		$hasPagination = isset($_GET['page']);
		if ($hasPagination)
			$url = preg_replace('/(.*page=)(\d+)(.*)/', '${1}'.$page.'$3', $_SERVER['REQUEST_URI']);
		else {
			$separator = empty($_GET) ? '?' : '&';
			$url = $_SERVER['REQUEST_URI'].$separator.'page='.$page;
		}

		return $url;
	}

	public function pluralize($count, $word) {
		return $count.' '.$word.($count > 1 ? 's' : '');
	}
}