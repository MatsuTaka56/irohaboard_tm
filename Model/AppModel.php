<?php
/**
 * @author        Kotaro Miura
 * @copyright     2015-2021 iroha Soft, Inc. (https://irohasoft.jp)
 */

App::uses('Model', 'Model');

/**
 * Application model for Cake.
 *
 * Add your application-wide methods in the class below, your models
 * will inherit them.
 *
 * @package app.Model
 */
class AppModel extends Model
{
	private $options = []; // メソッドチェーン を使用した場合に find() で使用するパラメータ
	private $is_object = false; // 連想配列をオブジェクトに変換するかどうか（実験的実装）
	
	/**
	 * 英数字チェック（マルチバイト対応）
	 * 
	 * @param array $check チェック対象
	 * @return bool OK:true, NG:false
	 */
	public function alphaNumericMB($check)
	{
		$value = array_values($check);
		$value = $value[0];
		
		return preg_match('/^[a-zA-Z0-9]+$/', $value);
	}

	/**
	 * findById() と同様の仕様
	 */
	public function get($id)
	{
		return $this->findById($id);
	}

	/**
	 * 既存の find() メソッドを上書き
	 * 
	 * @param string $type 取得形式（指定した場合は、通常の動きとなる。省略した場合はメソッドチェーンを利用し、最後に all(), fisrt() をつけてデータを取得）
	 * @param array $options 各種条件（$type を指定した場合のみ指定可能）
	 * @return array type を指定した場合は取得結果、省略した場合はメソッドチェーン用にインスタンスを返す
	 */
	public function find($type = null, $options = [])
	{
		if($type == null)
		{
			$this->options = [];
			return $this;
		}
		
		return parent::find($type, $options);
	}

	/**
	 * フィールドを指定
	 */
	public function select($value)
	{
		$this->options['fields'] = $value;
		return $this;
	}

	/**
	 * where 句を指定
	 */
	public function where($value)
	{
		$this->options['conditions'] = $value;
		return $this;
	}

	/**
	 * ソート順を指定（文字列もしくは配列で指定）
	 */
	public function order($value)
	{
		$this->options['order'] = $value;
		return $this;
	}

	/**
	 * group by を指定
	 */
	public function group($value)
	{
		$this->options['group'] = $value;
		return $this;
	}

	/**
	 * データの取得数を指定
	 */
	public function limit($value)
	{
		$this->options['limit'] = $value;
		return $this;
	}

	/**
	 * ページ番号を指定
	 */
	public function page($value)
	{
		$this->options['page'] = $value;
		return $this;
	}

	/**
	 * find('all')の結果を返す
	 */
	public function all()
	{
		if($this->is_object)
		{
			$data = parent::find('all', $this->options);
			
			// 連想配列[Model][field] を [field] に変更
			foreach($data as &$row)
			{
				$row = array_merge($row, $row[$this->name]);
				unset($row[$this->name]);
			}
			
			// [Model][field] を 削除
			$object= new stdClass();
			$data = $this->_arrayToObject($data, $object);
			
			return $data;
		}
		
		return parent::find('all', $this->options);
	}

	/**
	 * find('first')の結果を返す
	 */
	public function first()
	{
		if($this->is_object)
		{
			$data = parent::find('first', $this->options);
			
			// 連想配列[Model][field] を [field] に変更
			$data = array_merge($data, $data[$this->name]);
			
			// [Model][field] を 削除
			unset($data[$this->name]);
			
			$object= new stdClass();
			$data = $this->_arrayToObject($data, $object);
			
			return $data;
		}
		
		return parent::find('first', $this->options);
	}

	/**
	 * find('list')の結果を返す
	 */
	public function toList()
	{
		return parent::find('list', $this->options);
	}

	/**
	 * find('count')の結果を返す
	 */
	public function count()
	{
		return parent::find('count', $this->options);
	}

	/**
	 * クエリの結果を配列で返す
	 * 
	 * @param string $sql SQL
	 * @param array $params SQL用パラメータ
	 * @param string $table_name テーブル名
	 * @param string $field_name フィールド名
	 * @return array クエリの結果
	 */
	public function queryList($sql, $params, $table_name, $field_name)
	{
		$data = $this->query($sql, $params);
		
		$list = [];
		
		for($i=0; $i< count($data); $i++)
		{
			$list[$i] = $data[$i][$table_name][$field_name];
		}
		
		return $list;
	}


	/**
	 * 取得形式をオブジェクトに指定
	 */
	public function convert()
	{
		$this->is_object = true;
		return $this;
	}
	
	/**
	 * 配列をオブジェクトに変換
	 */
	private function _arrayToObject($array, &$obj)
	{
		foreach($array as $key => $value)
		{
			if(is_array($value))
			{
				// データが複数かつ連想配列の場合、オブジェクト名の最後に sをつける
				if(is_string($key))
					$key .= 's';
				
				$obj->{strtolower($key)} = new stdClass();
				$this->_arrayToObject($value, $obj->{strtolower($key)});
			}
			else
			{
				$obj->{strtolower($key)} = $value;
			}
		}
		return $obj;
	}

	/**
	 * 数字チェック
	 * 
	 * @param string	$val 対象数字
	 * @param int		$min_num 最小値
	 * @param int		$max_num 最大値
	 * @param int		$i 行番号
	 * @param string	$item 項目名
	 * @return bool		$is_error false:正常、true:エラー
	 * @return string	$err_msg エラーの場合、エラーメッセージ
	 */
	public function testNumCheck($val, $min_num, $max_num, $i, $item)
	{
		$err_msg = '';
		if ($val != null) {
			if (!(preg_match("/^[0-9]+$/", $val))) {
				$err_msg = '<li>' . $i . '行目 : ' . $item . 'が数字ではありません。</li>';
				return [true, $err_msg];
			}
			if (($val < $min_num) || ($val > $max_num)) {
				$err_msg = '<li>' . $i . '行目 : ' . $item . 'が' . $min_num . '～' . $max_num . 'ではありません。</li>';
				return [true, $err_msg];
			}
		}
		return [false, $err_msg];
	}

	/**
	 * インポート関係HTMLの新環境適合
	 * 
	 * @param string	$html インポートHtml
	 * @param int		$course_id コースID
	 * @return string	$newHtml 適合後のHTML
	 * @return array	$ex_files Htmlから抽出したファイルリスト
	 */
	public function adaptImportHtml($html, $course_id)
	{
		$newHtml = $html;
		$ex_files = [];
		if ($newHtml === null) {
			$newHtml = '<p><br></p>';
		} else {
			if (strstr($newHtml, 'file_image')) {
				if (preg_match_all('/file_image\/(.+?)\/\d+\"/', $newHtml, $rich_images) > 0) {
					foreach ($rich_images[1] as $image) {
						array_push($ex_files, $image);
					}
				}
				$newHtml = preg_replace_callback(
					'/(file_image\/.+?\/)\d+(\")/',
					function ($m) use ($course_id) {
						return $m[1] . $course_id . $m[2];
					},
					$html
				);
				$root_dir = basename(ROOT);
				$newHtml = preg_replace_callback(
					'/(img src=\"\/).+?(\/contents\/)/',
					function ($m) use ($root_dir) {
						return $m[1] . $root_dir . $m[2];
					},
					$newHtml
				);
			}

		}
		return [$newHtml, $ex_files];
	}

	/**
	 * インポート用CSVファイルのコメント行チェック
	 * 
	 * @param array		$row 1行分のデータ
	 * @param boolean	&$comment_flg コメント識別フラグ true:コメント内、false:コメント外
	 * @return boolean	1行分処理済か未処理か true:処理済、false:未処理
	 */
	public function comment_check($row, &$comment_flg)
	{
		preg_match('/^ *(.+?) *$/', implode(' ', $row), $line_text);
		// コメント開始ラインか？ 先頭が「/*」
		if (!$comment_flg) {
			if (substr_compare($line_text[1], "/*", 0, 2) == 0){
				if (substr_compare($line_text[1], "*/", -2, 2) == 0){
					return true;
				}
				$comment_flg = true;
				return true;
			}
		} else {
		// コメントブロック中か？
		// コメント終了ラインか？ 先頭が「*/」
			if (substr_compare($line_text[1], "*/", -2, 2) == 0){
				$comment_flg = false;
			}
			return true;
		}
		return false;
	}
}
