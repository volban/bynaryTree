<?php
  
  $url = "hogehoge"; //DBサーバー
  $user = "user"; //ユーザー
  $pass = "pass"; //パスワード
  $db = ""; //スキーマ

  // MySQLへ接続する
  $link = mysql_connect($url,$user,$pass) or die("MySQLへの接続に失敗しました。");

  // データベースを選択する
  $sdb = mysql_select_db($db,$link) or die("データベースの選択に失敗しました。");

  //起点
  $baseNo = "00001";

  // クエリを送信する
  $sql = "SELECT ";
  $sql .= "a.* ";
  $sql .= ",b.parent ";
  $sql .= ",LENGTH(group_path) - LENGTH(REPLACE(group_path,'#','')) as depth ";
  $sql .= ",SUBSTR(group_lr_path,(LENGTH(group_lr_path)),1) as lr ";
  $sql .= "FROM ";
  $sql .= "group_binary_map a ";
  $sql .= "LEFT OUTER JOIN ";
  $sql .= "( ";
  $sql .= "SELECT ";
  $sql .= "parent.main_no AS parent, ";
  $sql .= "child.main_no AS child ";
  $sql .= "FROM ";
  $sql .= "group_binary_map parent ";
  $sql .= "LEFT OUTER JOIN ";
  $sql .= "group_binary_map child ON ";
  $sql .= "parent.group_path = ";
  $sql .= "( ";
  $sql .= "SELECT ";
  $sql .= "MAX(group_path) ";
  $sql .= "FROM ";
  $sql .= "group_binary_map ";
  $sql .= "WHERE ";
  $sql .= "group_path = SUBSTR(child.group_path,1,(LENGTH(child.group_path) - 6 )) ";
  $sql .= ") ";
  $sql .= ") b ";
  $sql .= "ON a.main_no = b.child ";
  $sql .= "WHERE group_path LIKE CONCAT('%'," . $baseNo . ",'%') ";
  $sql .= "ORDER BY lr asc, depth asc ";

  $result = mysql_query($sql);
  if (!$result) {
      die('Invalid query: ' . mysql_error());
  }
  
  $arr_ret = array();
  while ($row = mysql_fetch_assoc($result)) {
      $arr_ret[] = $row;
  }
  
  // MySQLへの接続を閉じる
  mysql_close($link) or die("MySQL切断に失敗しました。");
  
  //最上位を設定
  $maxDepth;
  foreach( $arr_ret as $key => $value ){
      if($arr_ret[$key]['main_no'] == $baseNo){
          $arr_ret[$key]['parent'] = 0;
          $maxDepth = $arr_ret[$key]['depth'];
          break;
      }
  }
  
  //世代をリセット
  foreach( $arr_ret as $key => $value ){
       $arr_ret[$key]['depth'] -= $maxDepth - 1;
  }
  
  //ツリー作成
  $arrRetTree = setBtree($arr_ret);
  

  /**
   * バイナリツリー作成
   *
   * @param $arrTreeData
   */
  function setBtree($arrTreeData) {

      //最上位親ノードの配列
      $arrRoot  = array();
      //同ランク
      $arrBroth = array();
      //子配列
      $arrChild = array();
      //左右位置
      $arrLR  = array();
      //表示テキスト配列
      $arrText  = array();
      //深さ配列
      $arrDeep  = array();
      //主キー(名称)
      $arr_main_no  = array();
      
      foreach ($arrTreeData as $row) {

          //配列を変数へ配分
          
          //自身番号
          $no = $row['main_no'];
          //親番号
          $pno =  $row['parent'];
          //左右
          $lr =  $row['lr'];
          //深さ
          $deep =  $row['depth'];
          
          //親が0あれば最上位のルートとして保持(固定処理)
          if ($pno == 0) {
              $arrRoot[] = $no;
          } else {

             //最上位でない場合、自信と同じ親を持つ子配列が存在するか確認
             if(isset($arrChild[$pno])){
                 //存在する場合は同ランク親配列に
                 $arrBroth[$no]  = $arrChild[$pno];
             }else{
                 //存在しない場合は最上位とみなし配列へ設定
                 $arrBroth[$no]  = 0;
             }

             //親番号を持つ配列へ自身の番号を保持
             $arrChild[$pno] = $no;
          }

          //自身の表示位置
          $arrLR[$no] = $lr;
          //自身の深さ
          $arrDeep[$no] = $deep;
          
      }
      
      $arrRetTree = array();
      
      //親分をループし描画
      foreach ($arrRoot as $root) {
          
          put_tree($root, 
                          '', 
                          $arrBroth, 
                          $arrChild, 
                          $arrLR, 
                          $arrDeep,
                          $arrRetTree
                          );
                          
      }
      
      return $arrRetTree;
      

  }

  /**
   * 再帰ツリー文字列生成(再帰100回でエラーが出るので注意)
   *
   * @param $no
   * @param $line
   * @param $arrBroth
   * @param $arrChild
   * @param $arrLR
   * @param $deep
   * @param &$arrRetTree
   * @return void
   */
  function put_tree($no, 
                    $line, 
                    $arrBroth, 
                    $arrChild, 
                    $arrLR, 
                    $arrDeep,
                    &$arrRetTree
                    ) {
      $arrRetTree[] = array('deep' => $arrDeep[$no], 
                            'line_val' => $line, 
                            'lrs' => $arrLR[$no], 
                            'no' => $no
                            );
      
      //初回は際に上位ノードが入る line は空
      
      $line = preg_replace('/├$/', '│', $line);
      $line = preg_replace('/└$/', '　', $line);
      
      //25階層で折り返す。。。固定
      $orikaesi = 25;
      
      //折り返し位置の判定
      if(($arrDeep[$no] % $orikaesi) == 0){
          $temp = intval($arrDeep[$no] / $orikaesi);
          $line = '(' . $orikaesi * $temp .')';
      }

      //自身を親とする子番号を取得
      if(isset($arrChild[$no])){
          $no = $arrChild[$no];
      }else{
          $no = 0;
      }

      while ($no > 0) {
      
          if($arrBroth[$no]){
              $tail = '├';
          }else{
              $tail = '└';
          }

          put_tree($no, 
                  $line . $tail, 
                  $arrBroth, 
                  $arrChild, 
                  $arrLR, 
                  $arrDeep,
                  $arrRetTree);
                          
          $no = $arrBroth[$no];
      }
  }

?>

<html>
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=SHIFT-JIS">
    <title>バイナリー表示</title>
    
    <style type="text/css">
    <!--
    table.list  {
      border-spacing:0px;
      border-collapse:collapse;
      }
    .list th{
      background-color:#FDFDFD;
      text-align:left;
      font-weight:bold;
      white-space:nowrap;
      width:120px;
      }
    .list td,
    .list th{
      border:1px solid #E7E7E7;
      }
    -->
    </style>

  </head>
  <body>
  
  <table class="list">
  <tr><th>折り返し + No </th><th>左右</th><th>深さ</th></tr>
  <?php
      for ($i = 0; $i < count($arrRetTree); $i++) {
      
          echo "<tr>\n";
          echo "<td>" . $arrRetTree[$i]['line_val'] . "▼" . $arrRetTree[$i]['no'] . "</td>";
          if($arrRetTree[$i]['lrs'] == '1'){
              echo "<td>右</td>";
          }elseif($arrRetTree[$i]['lrs'] == '2'){
              echo "<td>左</td>";
          }else{
              echo "<td></td>";
          }
          echo "<td>" . $arrRetTree[$i]['deep'] . "</td>";
          echo "</tr>\n";

      }
  ?>
  </table>
  
  </body>
</html>
