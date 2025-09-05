<!DOCTYPE html>
<html>
<head>
	<?= $this->Html->charset(); ?>
	
	<title><?= $content['Content']['title']; ?></title>
	<meta name="application-name" content="<?= APP_NAME; ?>">
	<meta http-equiv="X-UA-Compatible" content="IE=edge"/>
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<?php
		echo $this->Html->meta('icon');

		echo $this->Html->css('jquery-ui');
		echo $this->Html->css('bootstrap.min');
		echo $this->Html->css('common.css');
		echo $this->Html->css('contents_view.css?20250601');
		echo $this->Html->css('custom.css');

		echo $this->Html->script('jquery-1.9.1.min.js');
		echo $this->Html->script('jquery-ui-1.9.2.min.js');
		echo $this->Html->script('bootstrap.min.js');
		echo $this->Html->script('common.js');
		echo $this->Html->script('contents_view.js?20250601');
		echo $this->Html->script('custom.js');

		echo $this->fetch('meta');
		echo $this->fetch('css');
		echo $this->fetch('script');
		echo $this->fetch('css-embedded');
		echo $this->fetch('script-embedded');
	?>
	<script>
	var URL_RECORDS_ADD		= '<?= Router::url(['controller' => 'records', 'action' => 'add', $content['Content']['id']])?>'; // 学習履歴保存用URL
	var URL_CONTNES_INDEX	= '<?= Router::url(['action' => 'index', $content['Course']['id']])?>'; // コンテンツ一覧画面
	var URL_CONTNES_VIEW	= '<?= Router::url(['action' => 'view'])?>'; // コンテンツ画面
	var BUTTON_PC_LIST		= <?= json_encode(Configure::read('record_understanding_pc')) ?>;
	var BUTTON_SPN_LIST		= <?= json_encode(Configure::read('record_understanding_spn')) ?>;
	</script>
</head>
<body>
<?php
	switch($content['Content']['kind'])
	{
		case 'url': // URLコンテンツ
			$body = '<iframe id="contentFrame" width="100%" height="100%" scrolling="yes" src="'.h($content['Content']['url']).'"></iframe>';
			break;
		case 'pict': // 画像コンテンツ
			$url = h($content['Content']['url']);

			if(strpos($url, 'http') === false)
			{
				$url = Router::url(['controller' => 'contents', 'action' => 'file_pict', $content['Content']['id']]);
			}

			$body = '<p><img src="'.$url.'"  data-filename="pict" id="imgsrc" style="width: 100%;"></p>';
			break;
		case 'movie': // 動画コンテンツ
			$url = h($content['Content']['url']);

			if(strpos($url, 'http') === false)
			{
				$url = Router::url(['controller' => 'contents', 'action' => 'file_movie', $content['Content']['id']]);
			}

			$body = '<video src="'.$url.'" controls width="100%" oncontextmenu="return false;"></video>';
			break;
		case 'text': // テキスト型コンテンツ
			$body = h($content['Content']['body']);
			$body = $this->Text->autoLinkUrls($body);
			$body = nl2br($body);
			break;
		case 'html': // リッチテキストコンテンツ
			$body = $content['Content']['body'];
			//$body = str_replace('src="/uploads/', 'src="'.Router::url(['controller' => 'contents', 'action' => 'file_image']).'/', $body);
			break;
	}
?>
<div class="content-view">
	<div class="content-title"><?= h($content['Content']['title'])?></div>
	<div class="content-body content-body-<?= $content['Content']['kind']?>"><?= $body;?></div>
	<?php
		$next_page = $content['Content']['next_page'];
		$prev_page = $content['Content']['prev_page'];
		//
		$mess_next_page = "コンテンツ一覧";
		$button_message = "戻るを選択した場合は学習履歴は残りません。";
		if ($next_page == 1) {
			$button_message = "次、".$button_message;
			$mess_next_page = "次ページ";
		}
		if ($prev_page == 1) {
			$button_message = "前、".$button_message;
		}
	?>
	<div class="content-foot">
		<div class="content-menu">
			<div class="select-message text-success"><?= __('理解度を選択して終了して下さい。');?></div>
			<span class='understanding-pc'></span>
			<span class='understanding-spn'></span>
			<button type="button" class="btn btn-danger" onclick="finish(0);"><?= __('中断');?></button>
			<?php if ($prev_page != 0) : ?>
				<button type="button" class="btn btn-primary" onclick="finish(-1, <?= $prev_page?>);"><?= __('前');?></button>
			<?php endif; ?>

			<button type="button" class="btn btn-primary" onclick="finish(-1);"><?= __('戻る');?></button>

			<?php if ($next_page != 0): ?>
				<button type="button" class="btn btn-primary" onclick="finish(-1, <?= $next_page?>);"><?= __('次');?></button>
			<?php endif; ?>
			<?= $this->Form->create('Record', ['url' => ['action' => 'add']]);?>
			<?= $this->Form->end(); ?>
		</div>
	</div>
</div>
</body>
</html>