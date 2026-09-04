<?= $this->element('admin_menu');?>
<div class="admin-courses-import">
<?= $this->Html->link(__('<< 戻る'), ['action' => 'index']) ?>
	<div class="panel panel-default">
		<div class="panel-heading">
			コースのインポート
		</div>
		<div class="panel-body">
			<li>コース情報およびコンテンツ情報、テスト問題情報が格納されたCSVファイルを選択し、インポートを行って下さい。</li>
			<li>CSVファイルの文字コードは「Shift-JIS」を使用してください。(UTF-8は使用できません。)</li>
			<li>行頭の「/*」から行末の「*/]はコメントとなります。</li>
			<li>各行は以下のフォームで指定します。１：コース情報、２：コンテンツ情報、３：テストコンテンツ情報、４：テスト問題情報です。</li>
			※エクスポート出力のCSVファイルを参考にしてください。<br>
			コース情報
			<table class="ib-table-import">
			<tr><th width="10">1</th>
			<th width="100" title="--------（必須）">コース名</th>
			<th width="100" title="コースの概略を指定します。">コース紹介</th>
			<th width="100">備考</th></tr></table>
			コンテンツ情報
			<table class="ib-table-import">
			<tr><th width="10">2</th>
			<th width="100" title="--------（必須）">コンテンツ名</th>
			<th width="100" title="「画像」「動画」「URL」「配布資料」「アンケート」
「リッチテキスト」を指定します。（必須）">コンテンツ種別</th>
			<th width="100" title="コンテンツ種別が画像、動画、配布資料で使用するファイル名を指定します。
files/コース名フォルダに保存されているファイルを指定します。">ファイル名</th>
			<th width="100" title="コンテンツ種別がURLの場合、使用するURLを指定します。
コンテンツ種別が画像、動画、配布資料の場合、使用するファイル名を指定します。">URL</th>
			<th width="100" title="コンテンツ種別がリッチテキストの場合、
使用するリッチテキストを指定します。">ページソース</th>
			<th width="100" title="コンテンツ種別が画像、動画、URL、リッチテキストの場合、
「学習」/「仕切り」を指定します。（規定値：「学習」）">コンテンツモード</th>
			<th width="100" title="「公開」/「非公開」を指定します。[非公開]と設定した場合、
管理者権限でログインした場合のみ表示されます。（規定値：「公開」）">ステータス</th>
			<th width="100">備考</th></tr></table>
			テストコンテンツ情報
			<table class="ib-table-import">
			<tr><th width="10">3</th>
			<th width="100" title="--------（必須）">コンテンツ名</th>
			<th width="100" title="「テスト」を指定します。（必須）">コンテンツ種別</th>
			<th width="100" title="１～１００（分）で指定します。指定した場合、
制限時間を過ぎると自動的に採点されます。">テスト制限時間</th>
			<th width="100" title="１～１００（％）の整数で指定します。指定した場合、
合否の判定が行われ、指定しない場合は無条件に合格となります。">合格得点率</th>
			<th width="100" title="１～１００（問）で指定します。指定した場合、
登録した問題の中からランダムに出題され、指定しない場合は
問題一覧画面の並び順で全問出題されます。">出題数</th>
			<th width="100" title="不正解時の表示：テスト結果画面にて不正解の問題の表示方法を指定します。
正解時は解説のみが表示されます。「１」：正解と解説を表示しない / 
「２」：正解と解説を表示する / 「３」：解説のみ表示する、の番号を指定します。
（規定値：「２」）">不正解時の表示</th>
			<th width="100"  title="「公開」/「非公開」を指定します。[非公開]と設定した場合、
管理者権限でログインした場合のみ表示されます。（規定値：「公開」）">ステータス</th>
			<th width="100" >備考</th></tr></table>
			テスト問題情報（関係するテストコンテンツの直後に配置してください）
			<table class="ib-table-import">
			<tr><th width="10">4</th>
			<th width="100" title="--------（必須）">問題名</th>
			<th width="100" title="テスト問題の内容を指定します。（必須）">問題文</th>
			<th width="100" title="回答のための選択肢を指定します。">選択肢</th>
			<th width="100" title="正解の選択肢番号を指定します。">正解</th>
			<th width="100" title="--------">得点</th>
			<th width="100" title="テスト問題の解説を指定します。">解説</th>
			<th width="100">備考</th></tr></table>
			<br>
			<?php
				// PHP8.1対応？
				$this->Form->unlockField('csvfile.full_path');

				echo $this->Form->create('Course', Configure::read('form_defaults2'));
				// CSVファイル
				echo '<div class="required">';
				echo $this->Form->input('csvfile',     ['label' => 'CSVファイル','type'=>'file']);
				echo '</div>';
				// ZIPファイル
				echo $this->Form->input('zipfile',     ['label' => 'ZIPファイル','type'=>'file']);
				// インポートボタン
				echo $this->Form->submit('インポート', Configure::read('form_submit_defaults'));
				//
				echo $this->Form->end();
			?>
			<div style="color:red;">
			<?= $err_msg; ?>
			</div>
		</div>
	</div>
</div>
