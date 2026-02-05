<?= $this->element('admin_menu');?>
<div class="admin-contents-questions-import">
<?= $this->Html->link(__('<< 戻る'), ['action' => 'index', $content_id]) ?>
	<div class="panel panel-default">
		<div class="panel-heading">
			テスト問題のインポート
		</div>
		<div class="panel-body">
			<li>テスト問題情報が格納されたCSVファイルを選択し、インポートを行って下さい。</li>
			<li>CSVファイルの文字コードは「Shift-JIS」を使用してください。(UTF-8は使用できません。)</li>
			<li>1行目はヘッダー行として扱われ、下記のヘッダ名称であることをチェックします。</li>
			<li>インポート処理がタイムアウトする場合は、CSVファイルを分割してインポートしてください。</li>
			<br>
			CSVの形式 ( * : 必須項目)
			<table class="ib-table-csv">
			<tr>
				<th>問題名</th>
				<th>問題文</th>
				<th>ファイル名</th>
				<th>選択肢</th>
				<th>正解</th>
				<th>得点</th>
				<th>解説</th>
				<th>備考</th>
			</tr>
			</table>
			<li>問題名：--------（必須）</li>
			<li>問題文：テスト問題の内容（必須）</li>
			<li>ファイル名：テスト問題に使用されているファイル名</li>
			<li>選択肢：回答のための選択肢</li>
			<li>正解：正解の選択肢番号</li>
			<li>得点：--------</li>
			<li>解説：テスト問題の解説</li>
			<li>備考：--------</li>
			<li>※上記項目以降のカラムは無視します。</li>
			<br>
			<?php
				$this->Form->unlockField('csvfile.full_path');
				echo $this->Form->create('ContentsQuestion',['type'=>'file']);
				echo $this->Form->input('csvfile',['label'=>'CSVファイル','type'=>'file']);
				echo $this->Form->input('zipfile',['label'=>'ZIPファイル','type'=>'file']);
				echo $this->Form->submit('インポート', Configure::read('form_submit_defaults'));
				echo $this->Form->end();
			?>
			<div style="color:red;">
			<?= $err_msg; ?>
			</div>
		</div>
	</div>
</div>
