<?php

namespace Fend;

use Fend\Core\RequestContext;
use Fend\Coroutine\Coroutine;

class Debug
{
    //start time
    protected const FIELD_START_TIME = "Debug.StartTime";

    //php://output string
    protected const FIELD_OUTPUT = "Debug.Output";

    //enable record
    protected const FIELD_ENABLE = "Debug.Enable";

    //enable record exception
    protected const FIELD_EXCEP = "Debug.Exception";

    //redis record exception
    protected const FIELD_REDIS = "Debug.Redis";

    //enable record exception
    protected const FIELD_CURL = "Debug.Curl";

    //sql info
    protected const FIELD_SQL = "Debug.SQL";

    //worker mode
    protected const FIELD_MODE = "Debug.Mode";

    /**
     *
     * @param string $mode fpm\Swoole\
     */
    public static function Init($mode = "fpm")
    {

        $data = [
            self::FIELD_OUTPUT => "",
            self::FIELD_ENABLE => 0,
            self::FIELD_EXCEP => [],
            self::FIELD_SQL => [],
            self::FIELD_START_TIME => microtime(true)
        ];

        //different mode
        switch ($mode) {
            case "fpm":
                $data[self::FIELD_MODE] = "fpm";
                break;
            case "cli":
                $data[self::FIELD_MODE] = "cli";
                break;
            case "swoole":
                if(Coroutine::inCoroutine()) {
                    $data[self::FIELD_MODE] = "swoole_v4";
                } else {
                    $data[self::FIELD_MODE] = "swoole_v1";
                }
                break;
            default:
                die("\\Fend\\Debug::Init 传入错误的运行状态");
        }
        RequestContext::setMulti($data);

    }

    /**
     * 开启debug输出
     * @param int $type debug类型
     * 0：关闭
     * 1：开启wxdebug模式 html界面
     * 2：开启wxdebug模式 var_dump输出所有信息
     * 3：不开启debug界面 记录xhprof Profile
     */
    public static function enableDebug($type = 1)
    {
        RequestContext::set(self::FIELD_ENABLE, $type);

        //will not work on Swoole Cororutine Mode
        if(!Coroutine::inCoroutine()) {
            ini_set('display_errors', 'on');//开启或关闭PHP异常信息
            error_reporting(E_ALL);//异常级别设置

            //Xhprof enable
            if ($type == 3 && function_exists("xhprof_enable")) {
                xhprof_enable(XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_CPU);
            }
        }

    }

    /**
     * 禁止debug记录
     */
    public static function disableDebug()
    {
        RequestContext::set(self::FIELD_ENABLE, 0);
    }

    /**
     * 是否Debug模式
     * @return bool
     */
    public static function isDebug()
    {
        return RequestContext::get(self::FIELD_ENABLE ,0) && Config::get("Fend.debug");
    }

    /**
     * 记录Exception
     * @param \Throwable $e
     */
    public static function appendException($e)
    {
        RequestContext::override(self::FIELD_EXCEP, function ($value) use ($e){
            //fetch multi exception
            do{
                $value[] = $e;
            }while(($e = $e->getPrevious()) !== null);

            return $value;
        });
    }

    /**
     * 追加运行期间控制台输出
     * @param $content
     */
    public static function appendDebugOutput($content)
    {
        RequestContext::override(self::FIELD_OUTPUT, function ($value) use ($content){
            return $value . $content;
        });
    }


    /**
     * 获取所有output
     * @return string
     */
    public static function getDebugOutput()
    {
        return RequestContext::get(self::FIELD_OUTPUT, "");
    }

    /**
     * 追加SQL信息
     * @param $sql
     */
    public static function appendSqlInfo($sql)
    {
        RequestContext::override(self::FIELD_SQL, function ($value) use ($sql){
            $value[] = $sql;
            return $value;
        });
    }

    /**
     * 追加Redis信息
     * @param $redis
     */
    public static function appendRedisInfo($redis)
    {
        RequestContext::override(self::FIELD_REDIS, function ($value) use ($redis){
            $value[] = $redis;
            return $value;
        });
    }

    /**
     * 追加curl信息
     * @param $curl
     */
    public static function appendCurlInfo($curl)
    {
        RequestContext::override(self::FIELD_CURL, function ($value) use ($curl){
            $value[] = $curl;
            return $value;
        });
    }

    /**
     * 获取从收到请求到现在耗时时间
     * @return float
     */
    public static function getRequestCostTime()
    {
        return round(microtime(true) - RequestContext::get(self::FIELD_START_TIME), 4);
    }

    /**
     * 异常信息展示
     * @param string $result
     * @return string
     * @throws \Exception
     */

    public static function show($result = "")
    {
        $request = Di::factory()->getRequest();
        $response = Di::factory()->getResponse();
        $response->header("Content-Type", "text/html; charset=utf-8");

        //enable xhprof dump
        if (RequestContext::get(self::FIELD_ENABLE ,0) == 3 &&
                function_exists("xhprof_disable") &&
                !Coroutine::inCoroutine()) {

            $profile = xhprof_disable();
            $profile = serialize($profile);
            $dumpPath = ini_get("xhprof.output_dir");
            if (empty($dumpPath)) {
                $dumpPath = sys_get_temp_dir();
            }
            $xhprofToken = uniqid();

            $dumpPath .= "/" . $xhprofToken . ".fend.xhprof";
            file_put_contents($dumpPath, $profile);

        }

        //show debug html
        if (RequestContext::get(self::FIELD_ENABLE ,0) == 1) {
            $exception = RequestContext::get(self::FIELD_EXCEP ,[]);
            $sql = RequestContext::get(self::FIELD_SQL ,[]);
            $debugOutput = nl2br(self::getDebugOutput());
            $included = get_included_files();
            $costTime = self::getRequestCostTime();
            $server = $request->server();
            $header = $request->header();
            $cookie = $request->cookie();
            $get = $request->get();
            $post = $request->post();
            $redis = RequestContext::get(self::FIELD_REDIS ,[]);

            //$result;
            ob_start();
            ?>
<!doctype html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
</head>
<body>
<?php
//exception
if (!empty($exception)) {
    ?>
    <h2> Exception </h2>
    <?php
    foreach ($exception as $k => $expItem) {
        ?>
        <ul>
            <li>Msg: <?php echo $expItem->getMessage(); ?> (<?php echo $expItem->getCode(); ?>)</li>
            <li>Code: (<?php echo $expItem->getCode(); ?>)</li>
            <li>File: <?php echo $expItem->getFile(); ?> </li>
            <li>Line: <?php echo $expItem->getLine(); ?></li>
            <li>Trace:
                <pre class="panel"><?php echo $expItem->getTraceAsString(); ?></pre>
            </li>
        </ul>
    <?php }
    ?>
    <hr/>
    <?php
}

//response
if (!empty($result)) {
    ?>
    <h2>Response(<?php echo strlen($result); ?>)</h2>
    <pre class="panel"><?php
        $r = json_decode($result);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo json_encode($r, JSON_PRETTY_PRINT);
        } else {
            echo $result;
        }
        ?></pre>
    <hr/>

    <div class="workmode"><?php echo RequestContext::get(self::FIELD_MODE,"cli"); ?></div>
    <?php
}

//Console
if (!empty($debugOutput)) {
    ?>
    <h2> Debug Output (<?php echo strlen($debugOutput); ?>)</h2>
    <pre class="panel"><?php echo $debugOutput; ?></pre>
    <hr/>
    <?php
}

//Redis INFO
if (!empty($redis)) {
    ?>
    <h2> Redis (<?php echo count($redis); ?>) </h2>

    <table width="100%" cellspacing="0" border="1">
        <thead>
        <tr>
            <th style="width: 50px;">NO.</th>
            <th style="width: 100px;">Mode</th>
            <th>OP</th>
            <th style="width: 50px;">Cost(Sec)</th>
            <th style="width: 100px;">Result Size</th>
        </tr>
        </thead>
        <?php
        if (!empty($redis)) {
            foreach ($redis as $k => $redisItem) {
                ?><tr>
                <td><?php echo $k; ?></td>
                <td><?php echo isset($redisItem["mode"]) ? $redisItem["mode"] : ""; ?></td>
                <td><?php echo strlen($redisItem["op"]) > 40 ? substr($redisItem["op"],0,40)."...": $redisItem["op"]; ?></td>
                <td><?php echo $redisItem["cost"]; ?></td>
                <td><?php echo $redisItem["result_len"]; ?></td>
                </tr><?php }
        } ?>
    </table>
    <hr/>

    <?php
}

//SQL INFO
if (!empty($sql)) {
    ?>
    <h2> SQL (<?php echo count($sql); ?>) </h2>

    <table width="100%" cellspacing="0" border="1">
        <thead>
        <tr>
            <th style="width: 50px;">NO.</th>
            <th style="width: 400px;">Mode</th>
            <th style="width: 400px;">SQL</th>
            <th style="width: 200px;">Info</th>
            <th style="width: 50px;">Cost(Sec)</th>
            <th style="width: 200px;">Explain</th>
        </tr>
        </thead>
        <?php
        if (!empty($sql)) {
            foreach ($sql as $k => $sqlItem) {
                ?><tr>
                <td><?php echo $k; ?></td>
                <td><?php echo isset($sqlItem["mode"]) ? $sqlItem["mode"] : ""; ?></td>
                <td><?php echo $sqlItem["sql"]; ?></td>
                <td><?php echo json_encode($sqlItem["info"]); ?></td>
                <td><?php echo $sqlItem["time"]; ?></td>
                <td><pre class="panel"><?php echo json_encode($sqlItem["explain"] ?? [], JSON_PRETTY_PRINT); ?></pre></td>
                </tr><?php }
        } ?>
    </table>
    <hr/>

    <?php
}
?>
<h2> File (<?php echo count($included); ?>) </h2>
<table width="100%" cellspacing="0" border="1">
    <thead>
    <tr>
        <th style="width: 50px">NO.</th>
        <th>Path</th>
        <th>Size</th>
    </tr>
    </thead>
    <tbody>
    <?php
    if (!empty($included)) {

        foreach ($included as $k => $fileItem) {
            ?><tr>
            <td width="30px"><?php echo $k; ?></td>
            <td><?php echo $fileItem; ?></td>
            <td width="80px" style="text-align: right;"><?php echo round(filesize($fileItem) / 1024, 1); ?>kb</td>
            </tr><?php
        }
    }
    ?>
    </tbody>
</table>
<hr/>

<h2> Env </h2>

<div class="nav_content system_msg">
    <table width="100%" cellspacing="0" border="1">
        <tbody>
        <thead>
        <tr>
            <th style="width: 200px;">Class</th>
            <th style="width: 200px;">Key</th>
            <th style="width: 400px;">Value</th>
            <th style="width: 50px;">Size</th>
        </tr>
        </thead>
        <tr>
            <td>REQUEST_TIME</td>
            <td>REQUEST_TIME</td>
            <td><?php echo $costTime; ?> (含当前页面渲染时间)
            </td>
            <td><?php echo strlen($costTime); ?></td>
        </tr>

<?php
if (!empty($server)) {

    foreach ($server as $key => $item) {
        ?>
<tr>
            <td>$_SERVER</td>
            <td><?php echo $key; ?></td>
            <td><?php if (is_array($item)) {
                    echo json_encode($item);
                } else {
                    echo $item;
                } ?></td>
            <td><?php echo strlen(json_encode($item)); ?></td>
        </tr>
        <?php
    }
} ?>
<?php
if (!empty($header)) {
    foreach ($header as $key => $item) {
        ?>
<tr>
            <td>HEADER</td>
            <td><?php echo $key; ?></td>
            <td><?php if (is_array($item)) {
                    echo json_encode($item);
                } else {
                    echo $item;
                } ?></td>
            <td><?php echo strlen(json_encode($item)); ?></td>
        </tr>
        <?php
    }
}
?>
<?php
if (!empty($cookie)) {
    foreach ($cookie as $key => $item) {
        ?>
<tr>
            <td>COOKIE</td>
            <td><?php echo $key; ?></td>
            <td><?php echo json_encode($item); ?></td>
            <td><?php echo strlen(json_encode($item)); ?></td>
        </tr>
        <?php
    }
} ?>
<?php

if (!empty($post)) {

    foreach ($post as $key => $item) {
        ?>
<tr>
            <td>$_POST</td>
            <td><?php echo $key; ?></td>
            <td><?php if (is_array($item)) {
                    echo json_encode($item);
                } else {
                    echo $item;
                } ?></td>
            <td><?php echo strlen(json_encode($item)); ?></td>
        </tr>
        <?php
    }
} ?>

<?php
if (!empty($get)) {
    foreach ($get as $key => $item) {
        ?>
<tr>
            <td>$_GET</td>
            <td><?php echo $key; ?></td>
            <td><?php if (is_array($item)) {
                    echo json_encode($item);
                } else {
                    echo $item;
                } ?></td>
            <td><?php echo strlen(json_encode($item)); ?></td>
        </tr>
        <?php
    }
} ?>
        </tbody>
    </table>

</div>
<style type="text/css">
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-size: 14px;
    }

    body {
        background-color: #eee;
        margin: 10px;
    }

    h1 {
        font-size: 38px;
    }

    h2 {
        font-size: 34px;
    }

    h3 {
        font-size: 30px;
    }

    h4 {
        font-size: 26px;
    }

    hr {
        margin: 5px 0 5px 0;
        clear: both;
    }

    .workmode {
        position: absolute;
        top: 10px;
        right: 10px;
        display: block;
        width: 80px;
        background-color: green;
        color: white;
        padding: 4px;
        clear: both;
        text-align: center;
    }

    li {
        list-style: none;
    }

    table {
        border: 1px solid #eef1f7;
        border-top-left-radius: 5px;
        border-top-right-radius: 5px;
        overflow: hidden;
    }

    thead {
        background-color: #607d8b;
        color: #fff;
    }

    th {
        padding: 5px;
    }

    td {
        color: #607d8b;
        padding: 5px 10px;
        text-align: left;
    }

    .panel {
        background-color: #1e1e1e;
        color: #fff;
        word-wrap: break-word;
        word-break: break-all;
        overflow: hidden;
        width: 100%;
        padding: 5px;
    }

</style>
</body>
</html>
            <?php
            $result = ob_get_clean();
        } elseif (RequestContext::get(self::FIELD_ENABLE ,0) == 2) {
            //show debug var_dump
            $result = array(
                "output" => $result,
                "workMode" => RequestContext::get(self::FIELD_MODE,"cli"),
                "exception" => RequestContext::get(self::FIELD_EXCEP ,[]),
                "sql" => RequestContext::get(self::FIELD_SQL ,[]),
                "echo" => self::getDebugOutput(),
                "costTime" => self::getRequestCostTime(),
                "server" => $request->server(),
                "header" => $request->header(),
                "cookie" => $request->cookie(),
                "queryString" => $request->get(),
                "post" => $request->post(),
                "redis" => RequestContext::get(self::FIELD_REDIS ,[]),
            );

            //$result;
            ob_start();
            var_dump($result);
            $result = ob_get_clean();
        }


        return $result;
    }
}