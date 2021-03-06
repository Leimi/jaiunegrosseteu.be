<?php
use RedBean_Facade as R;
class Model_Teube extends RedBean_SimpleModel
{
	public function open() {
		if (empty($this->views))
			$this->views = 0;
	}

	public function update() {
		if (empty($this->id)) {
			$this->created = date('Y-m-d H:i:s');
			$this->modified = date('Y-m-d H:i:s');
			$this->modified_count = 0;
			$this->active = 1;
			$this->generateUniqueSlug();

			$message = "La teube \"".$this->name."\" vient d'être ajoutée par ".$this->artist.".";
			$message .= "\n\nhttp://jaiunegrosseteu.be/de-".$this->slug."-cm";
			mail(APP_ADMIN_MAIL, "jaiunegrosseteu.be : nouvelle teube ! \"".$this->name."\"", $message, 'From:bot@jaiunegrosseteu.be');
		}
	}

	public function after_update() {
		if (!file_exists($this->getDrawingPath())) {
			if ($this->createTmpDrawingFile()) {
				$this->createDrawingFile();
				$this->createThumbnail();
				// suppression des fichiers temporaires/anciens
				//$this->deleteDrawingFiles(true);

				$this->image = null;
				R::store($this);
			}
		}
	}

	public function delete() {
		$this->deleteDrawingFiles();
	}

	public function getVotes() {
		$votes = R::find('voteub', 'teube_id = ? AND active = 1 ORDER BY created DESC', array($this->id));
		return $votes;
	}

	public function getSibling($field = 'id', $direction = 'next') {
		$query = "";
		//c'est pas terrible mais c'est déjà ça...
		//en faisant comme ça on choppe pas les items qui ont une valeur égale
		//mais si on prend les trucs avec valeur égale ça peut tourner en rond facilement ensuite, donc tant pis
		//pour l'instant on laisse comme ça
		if ($direction === "next")
			$query = $field.' = (SELECT MIN('.$field.') FROM teube WHERE active = 1 AND '.$field.' > ?) AND id <> ? AND active = 1';
		elseif ($direction === "prev")
			$query = $field.' = (SELECT MAX('.$field.') FROM teube WHERE active = 1 AND '.$field.' < ?) AND id <> ? AND active = 1';
		else
			return null;
		$teu = R::findOne('teube', $query, array($this->{$field}, $this->id));
		return $teu && $teu->id ? $teu : null;
	}

	public function report() {
		if (empty($this->reports))
			$this->reports = 0;
		$this->reports++;
		if ($this->reports > 5) {
			$message = "La teube ".$this->name." (n°".$this->id.") créée le ".date("d/m/Y", strtotime($this->created))." par ".$this->artist." a été signalée pour la ".$this->reports.($this->reports > 1 ? "ème" : "ère")." fois";
			$message .= "\n\nhttp://jaiunegrosseteu.be/de-".$this->slug."-cm";
			mail(APP_ADMIN_MAIL, "jaiunegrosseteu.be : ".$this->name." signalée", $message, 'From:bot@jaiunegrosseteu.be');
		}
		return R::store($this);
	}

	public function updatePageViews() {
		$piwikXMLFile = file_get_contents("http://".APP_PIWIK_SERVER."/index.php?module=API&method=CustomVariables.getCustomVariables&idSite=".APP_PIWIK_ID."&period=year&date=2013-09-01&format=xml&token_auth=".APP_PIWIK_API."&segment=customVariablePageName1==teubeView;customVariablePageValue1==".$this->id);
		if ($piwikXMLFile) {
			$piwikXML = simplexml_load_string($piwikXMLFile);
			$this->views = (int) $piwikXML->row->nb_actions;
			return R::store($this);
		}
		return false;
	}

	public function updateRatings() {
		$teubeVotes = $this->getVotes();
		$teubeVotesCount = count($teubeVotes);
		$voteValues = array();
		if (!$teubeVotesCount)
			return false;
		foreach ($teubeVotes as $vote)
			$voteValues[]= $vote->value;
		$this->avg_rating = array_sum($voteValues)/$teubeVotesCount;

		//http://masanjin.net/blog/bayesian-average
		$allTeubesAvgRating = R::getCell('SELECT AVG(avg_rating) FROM teube WHERE active = 1 AND avg_rating IS NOT NULL');
		if (empty($allTeubesAvgRating)) $allTeubesAvgRating = 3;

		$minVotesNumber = R::getCell('SELECT AVG(count) FROM (SELECT COUNT(*) as count FROM voteub WHERE active = 1 GROUP BY teube_id) as counts');
		$minVotesNumber = empty($minVotesNumber) || ceil($minVotesNumber/2) < 3 ? 3 : ceil($minVotesNumber/2);
		$this->w_rating = (($teubeVotesCount / ($teubeVotesCount + $minVotesNumber)) * $this->avg_rating) + (($minVotesNumber / ($teubeVotesCount+$minVotesNumber)) * $allTeubesAvgRating);

		$this->ratings_count = $teubeVotesCount;
		R::store($this);
	}

	public function getUserVote($fingerprint = false) {
		$query = 'active = 1 AND teube_id = ? AND ip = ? AND (ua = ? '.($fingerprint ? ' OR fingerprint = ?)' : ')');
		$bindings = array($this->id, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
		if ($fingerprint) $bindings[]= $fingerprint;
		$vote = R::findOne('voteub', $query, $bindings);
		return !empty($vote) && !empty($vote->id) ? $vote : null;
	}

	public function generateUniqueSlug($store = false, $force = false) {
		if (!empty($this->slug) && !$force)
			return false;
		$existing = R::getCol('SELECT slug FROM teube');
		$rand = (mt_rand(1, 9999))/100;
		while (in_array($rand, $existing))
			$rand = (mt_rand(1, 9999))/100;
		$this->slug = $rand;
		if ($store)
			R::store($this);
	}

	public function isDuplicate() {
		$query = 'active = 1 AND name = ? AND artist = ? AND ua = ? AND ip = ? AND created >= ?';
		$bindings = array($this->name, $this->artist, $this->ua, $this->ip, date('Y-m-d H:i:s', strtotime("-15 minutes")));
		$existing = R::findOne('teube', $query, $bindings);
		return !empty($existing);
	}

	public function getDrawingPath($suffix = '', $count = null) {
		if ($count === null) $count = $this->modified_count;
		return APP_STATIC_PATH.'/drawings/'.$this->id.(!empty($suffix) ? '.'.$suffix : '').('.'.$count).'.png';
	}

	public function getImageURI() {
		if (empty($this->image))
			$this->image = 'data:image/png;base64,'.base64_encode(file_get_contents($this->getDrawingPath()));
	}

	public function createTmpDrawingFile() {
		if (empty($this->image)) return false;
		//http://j-query.blogspot.fr/2011/02/save-base64-encoded-canvas-image-to-png.html
		$img = str_replace(' ', '+', str_replace('data:image/png;base64,', '', $this->image));
		$data = base64_decode($img);
		return file_put_contents($this->getDrawingPath(), $data);
	}

	public function createDrawingFile() {
		if (!file_exists($this->getDrawingPath('tmp'))) return false;
		$drawing = new PHPThumb\GD($this->getDrawingPath('tmp'));
		$drawing->resize(600, 600);
		$drawing->save($this->getDrawingPath());
	}

	public function createThumbnail() {
		if (!file_exists($this->getDrawingPath())) return false;
		$thumb = new PHPThumb\GD($this->getDrawingPath());
		$thumb->adaptiveResize(200, 200);
		$thumb->save($this->getDrawingPath('preview'));
	}

	public function deleteDrawingFiles($old = false) {
		$count = $old && $this->modified_count > 0 ? $this->modified_count -1 : null;
		if ($old && $count === null)
			return false;
		$this->deleteDrawingFile('tmp', $count);
		$this->deleteDrawingFile('preview', $count);
		$this->deleteDrawingFile('', $count);
	}

	public function deleteDrawingFile($suffix = '', $count = null) {
		if ($count === null) $count = $this->modified_count;
		$i = 0;
		while ($i <= $count) {
			if (file_exists($this->getDrawingPath($suffix, $i)))
				unlink($this->getDrawingPath($suffix, $i));
			$i++;
		}
		return true;
	}
}