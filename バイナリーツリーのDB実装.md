##二分木をDBにもって検索したりしなかったり


延々と続くバイナリーツリーを表現しなくてはならない事態に遭遇した時の考え方をメモ。基本的に他力本願。

やりたいことはツリーに追加、ツリーの可視化。
ツリーの更新・削除(位置の付け替えなど)はやらない。

oracleならば階層問い合わせでごにょごにょとも考えたが使うのはmysql。参考になったのは[MAKIZOU.COM](http://makizou.com/1616/)さん。できる人は違う。

-   隣接リストモデル<br>
    mysqlなので…
-   入れ子集合モデル<br>
    なんかパッと見理解に苦しんだので…
-   経路列挙モデル<br>
    わかりやすい。採用(ﾟ∀ﾟ)


サンプルはツリー構造だったが、実現したいのはバイナリー。考えを追加…パズルが得意な人がうらやましい。

*   自身の配下のどちらにいるかを保持。

延々と階層が続くためパスを1カラムにデータを保持すると桁あふれで破綻する。考えを追加…その発想がうらやましい。

1.  カラムに保持するパスの階層数を決める。
2.  階層を超えたらキーで紐づけた行を追加する。

サンプルはこんな感じ
<pre><code>
                             +-----+
                             |00001|
                             +--+--+
                                |
                        +-------+-------+
                        |               |
                     +-----+         +-----+
                     |00002|         |00003|
                     +--+--+         +--+--+
                        |
                +-------+-------+
                |               |
             +-----+         +-----+
             |00004|         |00005|
             +--+--+         +--+--+
                |               |
        +-------+               -------+
        |                              |
     +-----+                        +-----+
     |00006|                        |00007|
     +--+--+                        +--+--+
</code></pre>

テーブル
<pre><code>
CREATE TABLE `binary_map` (
  `main_no` varchar(6) NOT NULL,
  `depth_slide` int(11) NOT NULL,
  `path` varchar(255) NOT NULL,
  `lr_path` varchar(255) NOT NULL,
  PRIMARY KEY (`main_no`,`depth_slide`),
  KEY `binary_map_idx1` (`depth_slide`,`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='map'
</code></pre>

main_no:主キー
depth_slide:線形を分割した際の階層…わかりづらい日本語
path:線形のつながり。最上位から自分までのmain_noをdelimiter"#"を含め保持。
lr_path:pathと同様の持ち方で左右の配置を保持。0が最上位。1:左 2:右

データ
<pre><code>
INSERT INTO binary_map VALUES
( '00001', '1', '00001','0'),
( '00002', '1', '00001#00002','0#1'),
( '00003', '1', '00001#00003','0#2'),
( '00004', '1', '00001#00002#00004','0#1#1'),
( '00005', '1', '00001#00002#00005','0#1#2'),
( '00006', '1', '00001#00002#00004','0#1#2'),
( '00007', '1', '00001#00002#00005','0#1#2'),
( '00006', '2', '00006','1'),
( '00007', '2', '00007','2');
</code></pre>

pathに入るmain_noを「3」が最大と決めた場合の例です。
00006と00007が3を超えるのでdepth_slideを増やしたレコードが追加されています。

###全体像取得
<pre><code>
SELECT
    main_no
    ,GROUP_CONCAT(path ORDER BY depth_slide separator '#') AS group_path
    ,GROUP_CONCAT(lr_path ORDER BY depth_slide separator '#') AS group_lr_path
FROM
    binary_map
GROUP BY
    main_no
</code></pre>

<pre><code>
"main_no","group_path","group_lr_path"
"00001","00001","0"
"00002","00001#00002","0#1"
"00003","00001#00003","0#2"
"00004","00001#00002#00004","0#1#1"
"00005","00001#00002#00005","0#1#2"
"00006","00001#00002#00004#00006","0#1#2#1"
"00007","00001#00002#00005#00007","0#1#2#2"
</code></pre>

GROUP_CONCATで縦持ちを結合しちゃいます。
延々とつながるのでgroup_concat_max_lenの上限を上げておかないと破綻します。
もしくは別カラムをを用意し現在階層を保持する方法が良いかも。


##view化

毎回結合テーブルを打つのが面倒なのでViewにする

<pre><code>
CREATE VIEW group_binary_map
AS SELECT
    main_no
    ,GROUP_CONCAT(path ORDER BY depth_slide SEPARATOR '#') AS group_path
    ,GROUP_CONCAT(lr_path ORDER BY depth_slide SEPARATOR '#') AS group_lr_path
FROM
    binary_map
GROUP BY
    main_no
</code></pre>

※以降は(http://makizou.com/1616/)さんの方法論。

###ツリー構造よりルート（ツリー構造で最上位のノード）を取得

pathより区切り文字を消し、main_noと同じノードを取得することにより実現します。
<pre><code>
SELECT
    a.*
FROM
    (
        SELECT
            main_no
            ,GROUP_CONCAT(path ORDER BY depth_slide separator '#') AS group_path
            ,GROUP_CONCAT(lr_path ORDER BY depth_slide separator '#') AS group_lr_path
        FROM
            binary_map
        GROUP BY
            main_no
    ) a
WHERE
    a.main_no = REPLACE(a.group_path,'#','')
</code></pre>

<pre><code>
"main_no","group_path","group_lr_path"
"00001","00001","0"
</code></pre>



###ツリー構造よりリーフノード（子供がいないノード）を取得する

pathに「path + １字以上の任意文字」が該当しないノードを取得することにより実現します。
自身以外で自身と同じパスを持つものがいる場合は、子供がいるよってことのよう。頭いいね。
<pre><code>
SELECT
    a.*
FROM
    (
        SELECT
            main_no
            ,GROUP_CONCAT(path ORDER BY depth_slide separator '#') AS group_path
            ,GROUP_CONCAT(lr_path ORDER BY depth_slide separator '#') AS group_lr_path
        FROM
            binary_map
        GROUP BY
            main_no
    ) a
WHERE NOT EXISTS
    (
        SELECT
            b.*
        FROM
            (
                SELECT
                    main_no
                    ,GROUP_CONCAT(path ORDER BY depth_slide separator '#') AS group_path
                    ,GROUP_CONCAT(lr_path ORDER BY depth_slide separator '#') AS group_lr_path
                FROM
                    binary_map
                GROUP BY
                    main_no
            ) b
        WHERE b.group_path LIKE CONCAT(a.group_path,'_%')
    )
</code></pre>

<pre><code>
"main_no","group_path","group_lr_path"
"00001","00001","0"
</code></pre>

###ノードの深さを測る

<pre><code>
SELECT
    a.*
    ,LENGTH(group_path) - LENGTH(REPLACE(group_path,'#',''))
FROM
    (
        SELECT
            main_no
            ,GROUP_CONCAT(path ORDER BY depth_slide separator '#') AS group_path
            ,GROUP_CONCAT(lr_path ORDER BY depth_slide separator '#') AS group_lr_path
        FROM
            binary_map
        GROUP BY
            main_no
    ) a
ORDER BY group_path
</code></pre>

###ツリー構造の深さを測る

<pre><code>
SELECT
    MAX(LENGTH(group_path) - LENGTH(REPLACE(group_path,'#',''))+1)
FROM
    (
        SELECT
            main_no
            ,GROUP_CONCAT(path ORDER BY depth_slide separator '#') AS group_path
            ,GROUP_CONCAT(lr_path ORDER BY depth_slide separator '#') AS group_lr_path
        FROM
            binary_map
        GROUP BY
            main_no
    ) a
</code></pre>


###親子を探る

自身を除いた自身パスを持つもので一番長いパスのものが親
考えはわかるが下の発想には至らない<br>
"00001#00002#00005" LIKE CONCAT(group_path,'_%')　こんな問い合わせになる。。

<pre><code>
SELECT
    parent.main_no AS parent,
    child.main_no AS child
FROM
    group_binary_map parent
    LEFT OUTER JOIN
    group_binary_map child ON
    parent.group_path =
        -- 相関サブクエリー(自分を持つパスのなかの一番大きいパスを返す)
        (
        SELECT
            MAX(group_path)
        FROM
            group_binary_map
        WHERE
            child.group_path LIKE CONCAT(group_path,'_%')
        )
</code></pre>

自身だとこんな感じにしか思いつかない。無能。
コード長さが固定の時限定になっちゃいますな。。。

<pre><code>

SELECT
    parent.main_no AS parent,
    child.main_no AS child
FROM
    group_binary_map parent
    LEFT OUTER JOIN
    group_binary_map child ON
    parent.group_path = SUBSTR(child.group_path,1,(LENGTH(child.group_path) - 6 ))

</code></pre>

###ここまでの考えを合わせる。親を指定して配下をすべて取得する文を作成

<pre><code>
SELECT
    a.*
    ,b.parent -- 親
    ,LENGTH(group_path) - LENGTH(REPLACE(group_path,'#','')) as depth -- 深さ
    ,SUBSTR(group_lr_path,(LENGTH(group_lr_path)),1) as lr -- 左右
FROM
    group_binary_map a
    -- 親子関係を抽出したテーブルと紐づける
    LEFT OUTER JOIN
    (
      SELECT
        parent.main_no AS parent,
        child.main_no AS child
      FROM
          group_binary_map parent
          LEFT OUTER JOIN
          group_binary_map child ON
          parent.group_path =
              (
              SELECT
                  MAX(group_path)
              FROM
                  group_binary_map
              WHERE
                  group_path = SUBSTR(child.group_path,1,(LENGTH(child.group_path) - 6 ))
              )
      ) b
    
    ON a.main_no = b.child

WHERE group_path LIKE CONCAT('%',:main_no ,'%')
ORDER BY lr asc, depth asc
</code></pre>

データ
<pre><code>
"main_no","group_path","group_lr_path","parent","depth","lr"
"00001","00001","0",,0,"0"
"00002","00001#00002","0#1","00001",1,"1"
"00004","00001#00002#00004","0#1#1","00002",2,"1"
"00006","00001#00002#00004#00006","0#1#2#1","00004",3,"1"
"00003","00001#00003","0#2","00001",1,"2"
"00005","00001#00002#00005","0#1#2","00002",2,"2"
"00007","00001#00002#00005#00007","0#1#2#2","00005",3,"2"
</code></pre>


抽出ができたら表示加工は別プログラムで行う。
階層の表示はSQLではむずかしいさ。。。

表示ソースのせておくよー
