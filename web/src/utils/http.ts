/**
 * MineAdmin is committed to providing solutions for quickly building web applications
 * Please view the LICENSE file that was distributed with this source code,
 * For the full copyright and license information.
 * Thank you very much for using MineAdmin.
 *
 * @Author X.Mo<root@imoi.cn>
 * @Link   https://github.com/mineadmin
 */
import type { AxiosInstance, AxiosRequestConfig, AxiosResponse } from 'axios'
import axios from 'axios'
import Message from 'vue-m-message'
import { useDebounceFn } from '@vueuse/core'
import { useNProgress } from '@vueuse/integrations/useNProgress'
import useCache from '@/hooks/useCache.ts'
import { ResultCode } from './ResultCode.ts'

const { isLoading } = useNProgress()
const cache = useCache()
const requestList = ref<any[]>([])
const isRefreshToken = ref<boolean>(false)

function createHttp(baseUrl: string | null = null, config: AxiosRequestConfig = {}): AxiosInstance {
  const env = import.meta.env
  return axios.create({
    baseURL: baseUrl ?? (env.VITE_OPEN_PROXY === 'true' ? env.VITE_PROXY_PREFIX : env.VITE_APP_API_BASEURL),
    timeout: 1000 * 5,
    responseType: 'json',
    ...config,
  })
}

const http: AxiosInstance = createHttp()

http.interceptors.request.use(

  async (config) => {
    isLoading.value = true
    const userStore = useUserStore()
    /**
     * 全局拦截请求发送前提交的参数
     */
    if (userStore.isLogin && config.headers) {
      config.headers = Object.assign({
        'Authorization': `Bearer ${userStore.token}`,
        'Accept-Language': userStore.getLanguage(),
      }, config.headers)
    }

    await usePluginStore().callHooks('networkRequest', config)
    return config
  },
)

http.interceptors.response.use(
  async (response: AxiosResponse): Promise<any> => {
    isLoading.value = false
    const userStore = useUserStore()
    await usePluginStore().callHooks('networkResponse', response)
    const config = response.config
    if ((response.request.responseType === 'blob'
      || response.request.responseType === 'arraybuffer')
      && !/^application\/json/.test(response.headers['content-type'])
      && response.status === ResultCode.SUCCESS
    ) {
      return Promise.resolve(response.data)
    }

    if (response?.data?.code === ResultCode.SUCCESS) {
      return Promise.resolve(response.data)
    }
    else {
      switch (response?.data?.code) {
        case ResultCode.UNAUTHORIZED: {
          const logout = useDebounceFn(
            async () => {
              Message.error('登录状态已过期，需要重新登录', { zIndex: 9999 })
              await useUserStore().logout()
            },
            3000,
            { maxWait: 5000 },
          )
          // 检查token是否需要刷新
          if (userStore.isLogin && !isRefreshToken.value) {
            isRefreshToken.value = true
            if (!cache.get('refresh_token')) {
              await logout()
              break
            }

            try {
              const refreshTokenResponse = await createHttp(null, {
                headers: {
                  Authorization: `Bearer ${cache.get('refresh_token')}`,
                },
              }).post('/admin/passport/refresh')
              const { data } = refreshTokenResponse.data
              userStore.token = data.access_token
              cache.set('token', data.access_token)
              cache.set('expire', useDayjs().unix() + data.expire_at, { exp: data.expire_at })
              cache.set('refresh_token', data.refresh_token)

              config.headers!.Authorization = `Bearer ${userStore.token}`
              requestList.value.map((cb: any) => cb())
              requestList.value = []
              return http(config)
            }
            // eslint-disable-next-line unused-imports/no-unused-vars
            catch (e: any) {
              requestList.value.map((cb: any) => cb())
              await logout()
              break
            }
            finally {
              requestList.value = []
              isRefreshToken.value = false
            }
          }
          else {
            return new Promise((resolve) => {
              requestList.value.push(() => {
                config.headers!.Authorization = `Bearer ${cache.get('token')}`
                resolve(http(config))
              })
            })
          }
        }
        case ResultCode.NOT_FOUND:
          Message.error('服务器资源不存在', { zIndex: 9999 })
          break
        case ResultCode.FORBIDDEN:
          Message.error('没有权限访问此接口', { zIndex: 9999 })
          break
        case ResultCode.METHOD_NOT_ALLOWED:
          Message.error('请求方法不被允许', { zIndex: 9999 })
          break
        case ResultCode.FAIL:
          Message.error('服务器内部错误', { zIndex: 9999 })
          break
        default:
          Message.error(response?.data?.message ?? '未知错误', { zIndex: 9999 })
          break
      }

      return Promise.reject(response.data ? response.data : null)
    }
  },
  async (error: any) => {
    isLoading.value = false
    const serverError = useDebounceFn(async () => {
      if (error.response.status === 500) {
        Message.error(error.message ?? '服务器内部错误', { zIndex: 9999 })
      }
    }, 3000, { maxWait: 5000 })
    await serverError()
    return Promise.reject(error)
  },
)

export default {
  http,
  createHttp,
}
