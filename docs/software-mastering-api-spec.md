# Starwaves 软件母带对接协议（第一版）

这份文档给“软件母带服务”对接方使用。
目标：让 `Starwaves` 网站与软件母带服务建立自动闭环。

闭环流程：

1. 用户在 `Starwaves` 网站点击“软件母带制作”
2. 网站后端创建母带任务，并调用软件母带服务 API
3. 软件母带服务自动下载原曲
4. 软件母带服务完成处理后，自动回调网站
5. 网站接收预览/成品文件，上传到百度网盘
6. 网站更新歌曲记录与任务状态
7. 前台用户可直接试听和下载母带结果

## 1. 接入方式概览

采用双 Token 机制：

- `MASTERING_API_TOKEN`
  - 网站 -> 软件母带服务
  - 网站调用母带服务时使用
- `MASTERING_CALLBACK_TOKEN`
  - 软件母带服务 -> 网站
  - 母带服务回调网站时使用

不要共用一个 token。
所有 token 都只放在服务器环境变量中，不写入 Git 仓库。

## 2. 网站调用软件母带服务：创建任务

建议接口：

- `POST /api/mastering/jobs`

请求头：

```http
Authorization: Bearer MASTERING_API_TOKEN
Content-Type: application/json
```

请求体示例：

```json
{
  "job_id": "master_20260416_00057",
  "song_id": 57,
  "user_id": 12,
  "title": "回不去了",
  "source_url": "https://media.starwaves.com.cn/backend/netdisk_stream.php?id=57",
  "source_format": "mp3",
  "callback_url": "https://www.starwaves.com.cn/backend/master_callback.php",
  "callback_token": "MASTERING_CALLBACK_TOKEN",
  "want_preview": true,
  "want_master_wav": true,
  "want_master_mp3": true,
  "notes": "software mastering request from starwaves"
}
```

字段说明：

- `job_id`
  - 网站生成的唯一任务号
  - 必须全局唯一
- `song_id`
  - 网站内部歌曲 ID
- `user_id`
  - 网站内部用户 ID
- `title`
  - 歌曲标题
- `source_url`
  - 软件母带服务下载原曲的地址
  - 推荐使用网站自己的受控代理地址
- `source_format`
  - 原曲格式，例如 `mp3` / `wav`
- `callback_url`
  - 母带服务处理完成后回调的网站地址
- `callback_token`
  - 母带服务回调网站时使用
- `want_preview`
  - 是否需要返回试听预览
- `want_master_wav`
  - 是否需要返回 WAV 成品
- `want_master_mp3`
  - 是否需要返回 MP3 成品
- `notes`
  - 可选备注

成功返回示例：

```json
{
  "ok": true,
  "accepted": true,
  "job_id": "master_20260416_00057",
  "provider_job_id": "svc_894251",
  "status": "queued",
  "message": "mastering job accepted"
}
```

失败返回示例：

```json
{
  "ok": false,
  "accepted": false,
  "error": "invalid_token"
}
```

## 3. 可选接口：查询任务状态

如果软件母带服务支持状态查询，建议提供：

- `GET /api/mastering/jobs/{provider_job_id}`

请求头：

```http
Authorization: Bearer MASTERING_API_TOKEN
```

返回示例：

```json
{
  "ok": true,
  "job_id": "master_20260416_00057",
  "provider_job_id": "svc_894251",
  "status": "processing",
  "progress": 62,
  "message": "limiter processing"
}
```

建议统一状态值：

- `queued`
- `downloading`
- `processing`
- `uploading`
- `completed`
- `failed`

## 4. 软件母带服务回调网站：任务完成

建议接口：

- `POST /backend/master_callback.php`

请求头：

```http
Authorization: Bearer MASTERING_CALLBACK_TOKEN
Content-Type: application/json
```

回调请求体示例：

```json
{
  "job_id": "master_20260416_00057",
  "provider_job_id": "svc_894251",
  "song_id": 57,
  "status": "completed",
  "preview_file_url": "https://master-service.example.com/output/svc_894251_preview.mp3",
  "master_file_url": "https://master-service.example.com/output/svc_894251_master.wav",
  "master_mp3_url": "https://master-service.example.com/output/svc_894251_master.mp3",
  "meta": {
    "duration": 214.38,
    "sample_rate": 44100,
    "lufs": -10.5,
    "peak_db": -0.8
  }
}
```

字段说明：

- `job_id`
  - 网站创建时提供的任务号
- `provider_job_id`
  - 软件母带服务内部任务号
- `song_id`
  - 网站歌曲 ID
- `status`
  - 完成时传 `completed`
- `preview_file_url`
  - 预览文件下载地址
- `master_file_url`
  - WAV 成品下载地址
- `master_mp3_url`
  - MP3 成品下载地址
- `meta`
  - 可选技术元信息

网站收到回调后要做的事：

1. 校验回调 token
2. 根据 `job_id` / `song_id` 定位母带任务
3. 下载预览/成品文件
4. 上传文件到百度网盘
5. 更新歌曲与任务状态
6. 对前台开放试听/下载入口

网站成功响应示例：

```json
{
  "ok": true,
  "accepted": true,
  "job_id": "master_20260416_00057",
  "status": "archived"
}
```

## 5. 软件母带服务回调网站：任务失败

母带失败时，不要静默失败，必须回调网站。

回调示例：

```json
{
  "job_id": "master_20260416_00057",
  "provider_job_id": "svc_894251",
  "song_id": 57,
  "status": "failed",
  "error_code": "MASTERING_ENGINE_ERROR",
  "error_message": "processing timeout after 1800 seconds"
}
```

网站收到后应：

- 更新任务状态为失败
- 记录失败原因
- 前台或后台允许用户重试

## 6. 关于文件回传方式

第一版建议采用“URL 回传”，不要一开始直接上传大文件。

即：

- 软件母带服务先把结果文件放在自己的临时输出地址
- 通过回调把 `preview_file_url` / `master_file_url` / `master_mp3_url` 发给网站
- 网站后端再自行下载这些文件并归档到百度网盘

这样第一版最容易跑通。

后续如果需要，再升级为：

- 软件母带服务直接 `multipart/form-data` 上传文件到网站

## 7. 原曲下载 URL 要求

网站发给软件母带服务的 `source_url` 建议满足：

- 使用网站自己的代理地址
- 不暴露底层真实网盘裸链接
- 支持普通 `GET`
- 最好支持 `Range`
- 如需安全增强，可加入短期签名和过期时间

示例：

```text
https://media.starwaves.com.cn/backend/netdisk_stream.php?id=57
```

后续可升级成：

```text
https://media.starwaves.com.cn/backend/netdisk_stream.php?id=57&token=abc123&expires=1713250000
```

## 8. 网站侧推荐环境变量

网站建议配置：

```env
MASTERING_API_BASE=https://your-mastering-service.example.com
MASTERING_API_TOKEN=xxxxxx
MASTERING_CALLBACK_TOKEN=yyyyyy
MASTERING_CALLBACK_URL=https://www.starwaves.com.cn/backend/master_callback.php
```

软件母带服务建议配置：

```env
STARWAVES_MASTERING_API_TOKEN=xxxxxx
STARWAVES_CALLBACK_TOKEN=yyyyyy
```

## 9. 推荐数据库字段（网站侧）

网站侧建议至少维护一张软件母带任务表，例如：

- `master_jobs`

建议字段：

- `id`
- `song_id`
- `user_id`
- `job_id`
- `provider_job_id`
- `status`
- `source_url`
- `preview_archive_path`
- `master_archive_path`
- `master_mp3_archive_path`
- `error_message`
- `created_at`
- `updated_at`
- `completed_at`

如果暂时不新建表，也至少要保证：

- 能记录当前是哪首歌在跑软件母带
- 能记录第三方返回的任务号
- 能记录成功/失败状态
- 能记录预览/成品的归档路径

## 10. 安全要求

必须遵守：

- Token 只保存在服务器环境变量
- 不把 token 提交到 GitHub
- 回调接口必须验证 `Authorization` 头或签名
- 每个任务必须有唯一 `job_id`
- 网站对原曲下载 URL 保持控制权
- 不直接把长期正式播放地址暴露为第三方源地址

## 11. 第一版最小落地范围

为了尽快跑通，第一版只要求完成这 4 件事：

1. 网站可以创建软件母带任务
2. 软件母带服务可以自动下载原曲
3. 软件母带服务可以完成后回调网站
4. 网站可以接收结果、归档到百度网盘、更新状态

先不要一开始就做：

- 超复杂实时进度推送
- 太多母带参数选项
- 花哨的签名算法
- 多套输出模式
- 复杂队列调度系统

先跑通闭环，再逐步增强。

## 12. 当前推荐实施顺序

1. 软件母带服务确认支持 `POST /api/mastering/jobs`
2. 软件母带服务确认支持回调 `POST /backend/master_callback.php`
3. 双方先约定 token 方式
4. 用一首测试歌跑通全流程
5. 跑通后再补状态查询、失败重试、进度展示

---

如果软件母带服务方要和 `Starwaves` 网站正式联调，请按本协议第一版实现。
